/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

package vfsdev

import (
	"errors"
	"fmt"
	"sync"
	"time"
	"zabbix/internal/plugin"
)

// Plugin -
type Plugin struct {
	plugin.Base
	devices map[string]*devUnit
	mutex   sync.Mutex
}

var impl Plugin

const (
	maxInactivityPeriod = time.Hour * 3
	maxHistory          = 60*15 + 1
)

const (
	ioModeRead = iota
	ioModeWrite
)

const (
	statTypeSectors = iota
	statTypeOperations
	statTypeSPS
	statTypeOPS
)

type historyIndex int

func (h historyIndex) inc() historyIndex {
	h++
	if h == maxHistory {
		h = 0
	}
	return h
}

func (h historyIndex) dec() historyIndex {
	h--
	if h < 0 {
		h = maxHistory - 1
	}
	return h
}

func (h historyIndex) sub(value historyIndex) historyIndex {
	h -= value
	for h < 0 {
		h += maxHistory
	}
	return h
}

type devIO struct {
	sectors    uint64
	operations uint64
}

type devStats struct {
	clock int64
	rx    devIO
	tx    devIO
}

type devUnit struct {
	name       string
	head, tail historyIndex
	accessed   time.Time
	history    [maxHistory]devStats
}

var typeParams map[string]int = map[string]int{
	"":           statTypeSPS,
	"sps":        statTypeSPS,
	"ops":        statTypeOPS,
	"sectors":    statTypeSectors,
	"operations": statTypeOperations,
}

var rangeParams map[string]historyIndex = map[string]historyIndex{
	"":      60,
	"avg1":  60,
	"avg5":  60 * 5,
	"avg15": 60 * 15,
}

func (p *Plugin) Collect() (err error) {
	now := time.Now()
	p.mutex.Lock()
	for key, dev := range p.devices {
		if now.Sub(dev.accessed) > maxInactivityPeriod {
			p.Debugf(`removed unused device "%s" disk collector `, dev.name)
			delete(p.devices, key)
			continue
		}
	}
	err = p.collectDeviceStats(p.devices)
	p.mutex.Unlock()
	return
}

func (p *Plugin) Period() int {
	return 1
}

func (p *Plugin) Export(key string, params []string, ctx plugin.ContextProvider) (result interface{}, err error) {
	var mode int
	switch key {
	case "vfs.dev.read":
		mode = ioModeRead
	case "vfs.dev.write":
		mode = ioModeWrite
	case "vfs.dev.discovery":
		return p.getDiscovery()
	default:
		return nil, errors.New("Unsupported metric")
	}

	var devParam, typeParam, rangeParam string
	switch len(params) {
	case 3:
		rangeParam = params[2]
		fallthrough
	case 2:
		typeParam = params[1]
		fallthrough
	case 1:
		devParam = params[0]
		if devParam == "all" {
			devParam = ""
		}
	case 0:
	default:
		return nil, errors.New("Too many parameters.")
	}

	var ok bool
	var statType int
	if statType, ok = typeParams[typeParam]; !ok {
		return nil, errors.New("Invalid second parameter.")
	}

	if statType == statTypeSectors || statType == statTypeOperations {
		if len(params) > 2 {
			return nil, errors.New("Invalid number of parameters.")
		}
		var stats *devStats
		if stats, err = p.getDeviceStats(devParam); err != nil {
			return
		} else {
			if stats == nil {
				return nil, errors.New("Device not found.")
			}
			var devio *devIO
			if mode == ioModeRead {
				devio = &stats.rx
			} else {
				devio = &stats.tx
			}
			if statType == statTypeSectors {
				return devio.sectors, nil
			}
			return devio.operations, nil
		}
	}

	if ctx == nil {
		return nil, errors.New("This item is available only in daemon mode.")
	}

	var statRange historyIndex
	if statRange, ok = rangeParams[rangeParam]; !ok {
		return nil, errors.New("Invalid third parameter.")
	}

	var devName string
	if devName, err = p.getDeviceName(devParam); err != nil {
		return nil, fmt.Errorf("Cannot obtain device name: %s", err)
	}

	now := time.Now()
	p.mutex.Lock()
	defer p.mutex.Unlock()

	if dev, ok := p.devices[devName]; ok {
		dev.accessed = now
		totalnum := dev.tail - dev.head
		if totalnum < 0 {
			totalnum += maxHistory
		}
		if totalnum < 2 {
			p.Debugf("no device statistics have been gathered")
			return
		}
		if totalnum < statRange {
			statRange = totalnum
		}
		tail := &dev.history[dev.tail.dec()]
		head := &dev.history[dev.tail.sub(statRange)]

		var tailio, headio *devIO
		if mode == ioModeRead {
			tailio = &tail.rx
			headio = &head.rx
		} else {
			tailio = &tail.tx
			headio = &head.tx
		}
		if statType == statTypeSPS {
			return float64(tailio.sectors-headio.sectors) * float64(time.Second) / float64(tail.clock-head.clock), nil
		}
		return float64(tailio.operations-headio.operations) * float64(time.Second) / float64(tail.clock-head.clock), nil
	} else {
		p.devices[devName] = &devUnit{name: devName, accessed: now}
		return
	}
}

func init() {
	impl.devices = make(map[string]*devUnit)
	plugin.RegisterMetric(&impl, "vfsdev", "vfs.dev.read", "Disk read statistics.")
	plugin.RegisterMetric(&impl, "vfsdev", "vfs.dev.write", "Disk write statistics.")
	plugin.RegisterMetric(&impl, "vfsdev", "vfs.dev.discovery", "List of block devices and their type."+
		" Used for low-level discovery.")
}
