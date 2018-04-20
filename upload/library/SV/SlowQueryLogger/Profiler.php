<?php

class SV_SlowQueryLogger_Profiler extends XFCP_SV_SlowQueryLogger_Profiler
{
    /** @var float */
    protected $slowQuery             = 1.5;
    /** @var float  */
    protected $slowTransaction       = 1;
    /** @var bool */
    public $reportSlowQueries        = false;
    /** @var bool */
    protected $trackStacktraces      = false;
    /** @var int|null */
    protected $startTransactionTime  = null;
    /** @var int|null */
    protected $transactionEndQueryId = null;
    /** @var int */
    protected $startedTransaction    = 0;

    public function __construct($reportSlowQueries = true, $enabled = false)
    {
        parent::__construct($enabled);
        $this->trackStacktraces = XenForo_Application::getOptions()->sv_slowquery_trackstacks;
        $this->slowQuery = XenForo_Application::getOptions()->sv_slowquery_threshold;
        $this->slowTransaction = XenForo_Application::getOptions()->sv_slowtransaction_threshold;
        $this->reportSlowQueries = $reportSlowQueries;
    }

    /** @var Zend_Db_Profiler */
    static $slowQueryDb = null;
    /** @var Zend_Db_Adapter_Abstract */
    static $appDb = null;

    public static function injectSlowQueryDbConn()
    {
        if (self::$slowQueryDb === null)
        {
            /** @var XenForo_Application $app */
            $app = XenForo_Application::getInstance();
            self::$slowQueryDb = $app->loadDb(XenForo_Application::getConfig()->db);
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


    /**
     * @param int $queryId
     * @return string
     */
    public function queryEnd($queryId)
    {
        /*
        WARNING: this function is called after the query is finished initially executing, but not before all results are fetched.
        Invoking any XF function which touches XenForo_Application::getDb() will likely destroy any unfetched results!!!!
        must call injectSlowQueryDbConn/removeSlowQueryDbConn around any database access
        */
        if (!$this->reportSlowQueries)
        {
            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $ret = parent::queryEnd($queryId);

            if ($ret == self::STORED)
            {
                /** @var Zend_Db_Profiler_Query $qp */
                $qp = $this->_queryProfiles[$queryId];
                if ($this->trackStacktraces)
                {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $qp->stacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                }
            }

            return $ret;
        }
        $old = $this->_enabled;
        $this->_enabled = true;
        $queryEndTime = null;
        try
        {
            /** @noinspection PhpVoidFunctionResultUsedInspection */
            $ret = parent::queryEnd($queryId);
            $queryEndTime = microtime(true);

            if ($ret == self::STORED)
            {
                /** @var Zend_Db_Profiler_Query $qp */
                $qp = $this->_queryProfiles[$queryId];
                if ($this->trackStacktraces)
                {
                    /** @noinspection PhpUndefinedFieldInspection */
                    $qp->stacktrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                }
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
                        XenForo_Error::logException(new Exception('Slow query detected: ' . round($qp->getElapsedSecs(), 4) . ' seconds, ' . (empty($requestPaths['requestUri']) ? '' : $requestPaths['requestUri'])), false);
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
                XenForo_Error::logException(new Exception('Slow transaction detected: ' . round($queryEndTime, 4) . ' seconds, ' . (empty($requestPaths['requestUri']) ? '' : $requestPaths['requestUri'])), false);
            }
            finally
            {
                self::removeSlowQueryDbConn();
            }
        }

        return $ret;
    }
}

if (false)
{
    class XFCP_SV_SlowQueryLogger_Profiler extends Zend_Db_Profiler {}
}
