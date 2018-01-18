<?php

class SV_SlowQueryLogger_Debug extends XenForo_Debug
{
    public static function getDebugHtml()
    {
        if (XenForo_Application::isRegistered('page_start_time'))
        {
            $pageTime = microtime(true) - XenForo_Application::get('page_start_time');
        }
        else
        {
            $pageTime = 0;
        }

        $memoryUsage = memory_get_usage();
        $memoryUsagePeak = memory_get_peak_usage();

        if (XenForo_Application::isRegistered('db'))
        {
            $dbDebug = self::getDatabaseDebugInfo(XenForo_Application::getDb());
        }
        else
        {
            $dbDebug = array(
                'queryCount' => 0,
                'totalQueryRunTime' => 0,
                'queryHtml' => ''
            );
        }

        if ($pageTime > 0)
        {
            $dbPercent = ($dbDebug['totalQueryRunTime'] / $pageTime) * 100;
        }
        else
        {
            $dbPercent = 0;
        }

        $includedFiles = static::getIncludedFilesDebugInfo(get_included_files());

        $return = "<h1>Page Time: " . number_format($pageTime, 4) . "s</h1>"
                  . "<h2>Memory: " . number_format($memoryUsage / 1024 / 1024, 4) . " MB "
                  . "(Peak: " . number_format($memoryUsagePeak / 1024 / 1024, 4) . " MB)</h2>"
                  . "<h2>Queries ($dbDebug[queryCount], time: " . number_format($dbDebug['totalQueryRunTime'], 4) . "s, "
                  . number_format($dbPercent, 1) . "%)</h2>"
                  . $dbDebug['queryHtml']
                  . "<h2>Included Files ($includedFiles[includedFileCount], XenForo Classes: $includedFiles[includedXenForoClasses])</h2>"
                  . $includedFiles['includedFileHtml'];

        return $return;
    }

    public static function getDatabaseDebugInfo(Zend_Db_Adapter_Abstract $db)
    {
        $return = array(
            'queryCount' => 0,
            'totalQueryRunTime' => 0,
            'queryHtml' => ''
        );

        /* @var $profiler Zend_Db_Profiler */
        $profiler = $db->getProfiler();
        $return['queryCount'] = $profiler->getTotalNumQueries();

        if ($return['queryCount'])
        {
            $return['queryHtml'] .= '<ol>';

            /** @var Zend_Db_Profiler_Query[] $queries */
            $queries = $profiler->getQueryProfiles();
            foreach ($queries AS $query)
            {
                $queryText = rtrim($query->getQuery());
                if (preg_match('#(^|\n)(\t+)([ ]*)(?=\S)#', $queryText, $match))
                {
                    $queryText = preg_replace('#(^|\n)\t{1,' . strlen($match[2]) . '}#', '$1', $queryText);
                }

                $boundParams = array();
                foreach ($query->getQueryParams() AS $param)
                {
                    $boundParams[] = htmlspecialchars($param);
                }

                $explainOutput = '';

                if (preg_match('#^\s*SELECT\s#i', $queryText)
                    && $db instanceof Zend_Db_Adapter_Mysqli
                )
                {
                    $explainQuery = $db->query(
                        'EXPLAIN ' . $query->getQuery(),
                        $query->getQueryParams()
                    );
                    $explainRows = $explainQuery->fetchAll();
                    if ($explainRows)
                    {
                        $explainOutput .= '<table border="1">'
                                          . '<tr>'
                                          . '<th>Select Type</th><th>Table</th><th>Type</th><th>Possible Keys</th>'
                                          . '<th>Key</th><th>Key Len</th><th>Ref</th><th>Rows</th><th>Extra</th>'
                                          . '</tr>';

                        foreach ($explainRows AS $explainRow)
                        {
                            foreach ($explainRow AS $key => $value)
                            {
                                if (trim($value) === '')
                                {
                                    $explainRow[$key] = '&nbsp;';
                                }
                                else
                                {
                                    $explainRow[$key] = htmlspecialchars($value);
                                }
                            }

                            $explainOutput .= '<tr>'
                                              . '<td>' . $explainRow['select_type'] . '</td>'
                                              . '<td>' . $explainRow['table'] . '</td>'
                                              . '<td>' . $explainRow['type'] . '</td>'
                                              . '<td>' . $explainRow['possible_keys'] . '</td>'
                                              . '<td>' . $explainRow['key'] . '</td>'
                                              . '<td>' . $explainRow['key_len'] . '</td>'
                                              . '<td>' . $explainRow['ref'] . '</td>'
                                              . '<td>' . $explainRow['rows'] . '</td>'
                                              . '<td>' . $explainRow['Extra'] . '</td>'
                                              . '</tr>';
                        }

                        $explainOutput .= '</table>';
                    }
                }
                $stacktraceOutput = '';
                if (isset($query->stacktrace))
                {
                    $stacktraceOutput .= static::getBacktrace($query->stacktrace);
                }


                $return['queryHtml'] .= '<li>'
                                        . '<pre>' . htmlspecialchars($queryText) . '</pre>'
                                        . ($boundParams ? '<div><strong>Params:</strong> ' . implode(', ', $boundParams) . '</div>' : '')
                                        . '<div><strong>Run Time:</strong> ' . number_format($query->getElapsedSecs(), 6) . '</div>'
                                        . $explainOutput
                                        . $stacktraceOutput
                                        . "</li>\n";

                $return['totalQueryRunTime'] += $query->getElapsedSecs();
            }

            $return['queryHtml'] .= '</ol>';
        }

        return $return;
    }

    protected static function getBacktrace(array $backtrace, $ignore = 1)
    {
        $trace = '<pre>';
        $rootDir = XenForo_Autoloader::getInstance()->getRootDir();
        $rootDir = dirname($rootDir) . '/';
        foreach ($backtrace as $k => $v)
        {
            if ($k < $ignore)
            {
                continue;
            }

            if (isset($v['args']))
            {
                array_walk(
                    $v['args'], function (&$item, $key) {
                    $item = var_export($item, true);
                }
                );
            }
            else
            {
                $v['args'] = [];
            }

            $v['file'] = str_replace($rootDir, '', $v['file']);

            $trace .= '#' . ($k - $ignore) . ' ' . $v['file'] . '(' . $v['line'] . '): ' . (isset($v['class']) ? $v['class'] . '->' : '') . $v['function'] . '(' . implode(', ', $v['args']) . ')' . "\n";
        }

        return $trace.'</pre>';
    }
}
