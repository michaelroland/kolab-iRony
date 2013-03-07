<?php

namespace Kolab\Utils;

use Sabre\VObject\Property;

/**
 * Helper class proviting utility functions for VObject data encoding
 */
class VObjectUtils
{

    /**
     * Helper method to correctly interpret an all-day date value
     */
    public static function convert_datetime($prop)
    {
        if (empty($prop)) {
            return null;
        }
        else if ($prop instanceof Property\MultiDateTime) {
            $dt = array();
            $dateonly = ($prop->getDateType() & Property\DateTime::DATE);
            foreach ($prop->getDateTimes() as $item) {
                $item->_dateonly = $dateonly;
                $dt[] = $item;
            }
        }
        else if ($prop instanceof Property\DateTime) {
            $dt = $prop->getDateTime();
            if ($prop->getDateType() & Property\DateTime::DATE) {
                $dt->_dateonly = true;
            }
        }
        else if ($prop instanceof \DateTime) {
            $dt = $prop;
        }

        return $dt;
    }


    /**
     * Create a Sabre\VObject\Property instance from a PHP DateTime object
     *
     * @param string Property name
     * @param object DateTime
     */
    public static function datetime_prop($name, $dt, $utc = false)
    {
        $vdt = new Property\DateTime($name);
        $vdt->setDateTime($dt, $dt->_dateonly ? Property\DateTime::DATE : ($utc ? Property\DateTime::UTC : Property\DateTime::LOCALTZ));
        return $vdt;
    }

    /**
     * Copy values from one hash array to another using a key-map
     */
    public static function map_keys($values, $map)
    {
        $out = array();
        foreach ($map as $from => $to) {
            if (isset($values[$from]))
                $out[$to] = $values[$from];
        }
        return $out;
    }

}