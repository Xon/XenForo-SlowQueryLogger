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

    static $slowQueryDb = null;
    static $appDb = null;

    public static function injectSlowQueryDbConn()
    {
        if (self::$slowQueryDb === null)
        {
            self::$slowQueryDb = XenForo_Application::getInstance()->loadDb(XenForo_Application::getConfig()->db);
            // prevent recursive profiling
            self::$slowQueryDb->setProfiler(false);
        }
        if (self::$appDb !== null)
        {
            throw new Exception('Nesting calls to injectSlowQueryDbConn is not supported');
        }
        self::$appDb = XenForo_Application::get('db');
        XenForo_Application::set('db', self::$slowQueryDb);
    }

    public static function removeSlowQueryDbConn()
    {
        if (self::$appDb === null)
        {
            throw new Exception('Must call injectSlowQueryDbConn before removeSlowQueryDbConn');
        }
        XenForo_Application::set('db', self::$appDb);
        self::$appDb = null;
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
    /*
    WARNING: this function is called after the query is finished initially executing, but not before all results are fetched.
    Invoking any XF function which touches XenForo_Application::getDb() will likely destroy any unfetched results!!!!
    must call injectSlowQueryDbConn/removeSlowQueryDbConn around any database access
    */
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
                    self::injectSlowQueryDbConn();
                    try
                    {
                        XenForo_Error::logException(new Exception('Slow query detected: '.round($qp->getElapsedSecs(),4).' seconds, '.(empty($requestPaths['requestUri']) ? '': $requestPaths['requestUri'])), false);
                    }
                    finally
                    {
                        self::removeSlowQueryDbConn();
                    }
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
            self::injectSlowQueryDbConn();
            try
            {
                XenForo_Error::logException(new Exception('Slow transaction detected: '.round($queryEndTime,4).' seconds, '.(empty($requestPaths['requestUri']) ? '': $requestPaths['requestUri'])), false);
            }
            finally
            {
                self::removeSlowQueryDbConn();
            }
        }

        return $ret;
    }
}