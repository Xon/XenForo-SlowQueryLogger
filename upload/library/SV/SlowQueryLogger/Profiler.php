<?php

class SV_SlowQueryLogger_Profiler extends Zend_Db_Profiler
{
    protected $reportSlowQueries = false;
    protected $slowQuery = 1.0;

    public function __construct($reportSlowQueries = true, $slowQuery = 0.5, $enabled = false)
    {
        $this->slowQuery = $slowQuery;
        $this->reportSlowQueries = $reportSlowQueries;
        $this->setEnabled($enabled);
    }

    public function queryStart($queryText, $queryType = null)
    {
        if (!$this->_enabled && $this->reportSlowQueries)
        {
            $this->_enabled = true;
            try
            {
                return parent::queryStart($queryText, $queryType);
            }
            finally
            {
                $this->_enabled = false;
            }
        }
        return parent::queryStart($queryText, $queryType);
    }

    public function queryEnd($queryId)
    {
        if ($this->reportSlowQueries)
        {
            $old = $this->_enabled;
            $this->_enabled = true;
            try
            {
                $ret = parent::queryEnd($queryId);

                if ($ret == self::STORED)
                {
                    $qp = $this->_queryProfiles[$queryId];
                    if ($qp->getElapsedSecs() > $this->slowQuery)
                    {
                        static $requestPaths = null;
                        if ($requestPaths === null)
                        {
                            $requestPaths = XenForo_Application::get('requestPaths');
                        }
                        XenForo_Error::logException(new Exception('Slow query detected: '.round($qp->getElapsedSecs(),4).' seconds, '.(empty($requestPaths['requestUri']) ? '': $requestPaths['requestUri'])), false);
                        if (!$old)
                        {
                            unset($this->_queryProfiles[$queryId]);
                            return self::IGNORED;
                        }
                    }
                }
                return $ret;
            }
            finally
            {
                $this->_enabled = $old;
            }
        }
        return parent::queryEnd($queryId);
    }
}