<?php

namespace SonarSoftware\Poller\DeviceMappers\Cambium;

use Exception;
use SonarSoftware\Poller\DeviceMappers\BaseDeviceMapper;
use SonarSoftware\Poller\DeviceMappers\DeviceMapperInterface;
use SonarSoftware\Poller\Formatters\Formatter;
use SonarSoftware\Poller\Models\Device;
use SonarSoftware\Poller\Models\DeviceInterface;

class CambiumCanopyPMPAccessPointMapper extends BaseDeviceMapper implements DeviceMapperInterface
{
    public function mapDevice():Device
    {
        $this->setSystemMetadataOnDevice();
        $arrayOfDeviceInterfacesIndexedByInterfaceIndex = $this->getInterfacesWithStandardMibData(true, false);
        $arrayOfDeviceInterfacesIndexedByInterfaceIndex = $this->getConnectedSms($arrayOfDeviceInterfacesIndexedByInterfaceIndex);
        $this->device->setInterfaces($arrayOfDeviceInterfacesIndexedByInterfaceIndex);

        return $this->device;
    }

    /**
     * These SMs are always connected to the 2nd interface, which is the multipoint wireless interface
     * @param array $arrayOfDeviceInterfacesIndexedByInterfaceIndex
     * @return array|mixed
     */
    private function getConnectedSms(array $arrayOfDeviceInterfacesIndexedByInterfaceIndex):array
    {
        $keyToUse = 0;
        foreach ($arrayOfDeviceInterfacesIndexedByInterfaceIndex as $key => $deviceInterface)
        {
            if (strpos($deviceInterface->getDescription(),"MultiPoint") !== false)
            {
                $keyToUse = $key;
                break;
            }
        }

        $existingMacs = $arrayOfDeviceInterfacesIndexedByInterfaceIndex[$keyToUse]->getConnectedMacs(DeviceInterface::LAYER1);
        $registeredStates = [];

        try {
            //This will return the 0A MAC, but the data may be in Sonar as 2A or 3A, so we add those too.
            $result = $this->snmp->walk("1.3.6.1.4.1.161.19.3.1.4.1.3");
            $states = $this->snmp->walk("1.3.6.1.4.1.161.19.3.1.4.1.19");

            foreach ($states as $stateKey => $state)
            {
                $state = $this->cleanSnmpResult($state);
                if ($state == 1)
                {
                    $boom = explode(".",$stateKey);
                    array_push($registeredStates,$boom[count($boom)-1]);
                }
            }

            foreach ($result as $key => $datum)
            {
                $boom = explode(".",$key);
                try {
                    $mac = Formatter::formatMac($this->cleanSnmpResult($datum));
                    if ($this->validateMac($mac) && in_array($boom[count($boom)-1],$registeredStates))
                    {
                        array_push($existingMacs,$mac);
                        //Manipulate this to be the 2A and 3A as well
                        array_push($existingMacs,"2" . substr($mac,1));
                        array_push($existingMacs,"3" . substr($mac,1));
                    }
                }
                catch (Exception $e)
                {
                    continue;
                }
            }
        }
        catch (Exception $e)
        {
            //
        }

        $arrayOfDeviceInterfacesIndexedByInterfaceIndex[$keyToUse]->setConnectedMacs($existingMacs,DeviceInterface::LAYER1);

        return $arrayOfDeviceInterfacesIndexedByInterfaceIndex;
    }
}