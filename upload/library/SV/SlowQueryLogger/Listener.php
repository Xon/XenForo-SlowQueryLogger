<?php

class SV_SlowQueryLogger_Listener
{
    public static function init(XenForo_Dependencies_Abstract $dependencies, array $data)
    {
        if ($dependencies instanceof XenForo_Dependencies_Public)
        {
            // install a profiler            
        }
    }
}