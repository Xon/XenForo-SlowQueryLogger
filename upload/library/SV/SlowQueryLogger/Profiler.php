<?php

class SV_SlowQueryLogger_Profiler extends XFCP_SV_SlowQueryLogger_Profiler
{
    protected $slowQuery = 1.5;
    protected $slowTransaction = 1;
    protected $reportSlowQueries = false;
    protected $startTransactionTime = null;
    protected $transactionEndQueryId = null;
    protected $startedTransaction = 0;

    public function __construct($reportSlowQueries = true, $enabled = false)
    {
        $this->slowQuery = XenForo_Application::getOptions()->sv_slowquery_threshold;
        $this->slowTransaction = XenForo_Application::getOptions()->sv_slowtransaction_threshold;
        $this->reportSlowQueries = $reportSlowQueries;
        $this->setEnabled($enabled);
    }

    public function queryStart($queryText, $queryType = null)
    {
        if (!$this->reportSlowQueries)
        {
            return parent::queryStart($queryText, $queryType);
        }
        $captureQueryId = false;
        switch ($queryText)
        {
            case 'begin':
                // transaction start
                if ($this->startedTransaction === 0)
                {
                    $this->startTransactionTime = microtime(true);
                }
                $this->startedTransaction += 1;
                break;
            case 'rollback':
            case 'commit':
                $this->startedTransaction -= 1;
                if ($this->startedTransaction === 0)
                {
                    $captureQueryId = true;
                }
                break;
        }

        $old = $this->_enabled;
        $this->_enabled = true;
        try
        {
            $queryId = parent::queryStart($queryText, $queryType);
        }
        finally
        {
            $this->_enabled = $old;
            if ($captureQueryId)
            {
                $this->transactionEndQueryId = $queryId;
            }
        }
        return $queryId;
    }

    public function queryEnd($queryId)
    {
        if (!$this->reportSlowQueries)
        {
            return parent::queryEnd($queryId);
        }
        $old = $this->_enabled;
        $this->_enabled = true;
        $queryEndTime = null;
        try
        {
            $ret = parent::queryEnd($queryId);
            $queryEndTime = microtime(true);

            if ($ret == self::STORED)
            {
                $qp = $this->_queryProfiles[$queryId];
                if ($qp->getElapsedSecs() >= $this->slowQuery)
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
                        $ret = self::IGNORED;
                    }
                }
            }

        }
        finally
        {
            $this->_enabled = $old;
        }

        $queryEndTime = $queryEndTime - $this->startTransactionTime;
        if ($this->transactionEndQueryId !== null && $this->transactionEndQueryId === $queryId &&
            $queryEndTime >= $this->slowTransaction)
        {
            $this->transactionEndQueryId = null;
            static $requestPaths = null;
            if ($requestPaths === null)
            {
                $requestPaths = XenForo_Application::get('requestPaths');
            }
            XenForo_Error::logException(new Exception('Slow transaction detected: '.round($queryEndTime,4).' seconds, '.(empty($requestPaths['requestUri']) ? '': $requestPaths['requestUri'])), false);
        }

        return $ret;
    }
}