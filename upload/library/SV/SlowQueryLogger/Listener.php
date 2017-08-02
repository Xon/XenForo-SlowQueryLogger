<?php

class SV_SlowQueryLogger_Listener
{
    public static function init(XenForo_Dependencies_Abstract $dependencies, array $data)
    {
        if ($dependencies instanceof XenForo_Dependencies_Public && !class_exists('XFCP_SV_SlowQueryLogger_Profiler', false))
        {
            $db = XenForo_Application::getDb();
            $class = null;
            if ($object = $db->getProfiler())
            {
                if (!($object instanceof Zend_Db_Profiler))
                {
                    return;
                }
                $class = get_class($object);
            }
            if (empty($class))
            {
                $class = 'Zend_Db_Profiler';
            }
            eval('class XFCP_SV_SlowQueryLogger_Profiler extends ' .$class. ' {}');
            $db->setProfiler(new SV_SlowQueryLogger_Profiler());
        }
    }
}