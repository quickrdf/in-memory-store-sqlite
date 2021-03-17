<?php

/*
 * This file is part of the sweetrdf/InMemoryStoreSqlite package and licensed under
 * the terms of the GPL-3 license.
 *
 * (c) Konrad Abicht <hi@inspirito.de>
 * (c) Benjamin Nowack
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace sweetrdf\InMemoryStoreSqlite\Store;

use Exception;
use sweetrdf\InMemoryStoreSqlite\Log\LoggerPool;
use sweetrdf\InMemoryStoreSqlite\Parser\SPARQLPlusParser;
use sweetrdf\InMemoryStoreSqlite\PDOSQLiteAdapter;
use sweetrdf\InMemoryStoreSqlite\Serializer\TurtleSerializer;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\AskQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\ConstructQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\DeleteQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\DescribeQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\InsertQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\LoadQueryHandler;
use sweetrdf\InMemoryStoreSqlite\Store\QueryHandler\SelectQueryHandler;

class InMemoryStoreSqlite
{
    private PDOSQLiteAdapter $db;

    private LoggerPool $loggerPool;

    public function __construct(PDOSQLiteAdapter $db, LoggerPool $loggerPool)
    {
        $this->db = $db;
        $this->loggerPool = $loggerPool;
    }

    public function getLoggerPool(): LoggerPool
    {
        return $this->loggerPool;
    }

    public function getDBObject(): ?PDOSQLiteAdapter
    {
        return $this->db;
    }

    public function getDBVersion()
    {
        return $this->db->getServerVersion();
    }

    private function toTurtle($v): string
    {
        $ser = new TurtleSerializer();

        return (isset($v[0]) && isset($v[0]['s']))
            ? $ser->getSerializedTriples($v)
            : $ser->getSerializedIndex($v);
    }

    /**
     * @todo remove?
     */
    public function insert($data, $g, $keep_bnode_ids = 0)
    {
        if (\is_array($data)) {
            $data = $this->toTurtle($data);
        }

        if (empty($data)) {
            // TODO required to throw something here?
            return;
        }

        $infos = ['query' => ['url' => $g, 'target_graph' => $g]];
        $h = new LoadQueryHandler($this, $this->loggerPool->createNewLogger('Load'));

        return $h->runQuery($infos, $data, $keep_bnode_ids);
    }

    public function delete($doc, $g)
    {
        if (!$doc) {
            $infos = ['query' => ['target_graphs' => [$g]]];
            $h = new DeleteQueryHandler($this, $this->loggerPool->createNewLogger('Delete'));
            $r = $h->runQuery($infos);

            return $r;
        }
    }

    /**
     * Executes a SPARQL query.
     *
     * @param string $q             SPARQL query
     * @param string $result_format Possible values: infos, raw, rows, row
     * @param string $src
     *
     * @return array|int array if query returned a result, 0 otherwise
     */
    public function query($q, $result_format = '', $src = '')
    {
        $errors = [];

        if (preg_match('/^dump/i', $q)) {
            $infos = ['query' => ['type' => 'dump']];
        } else {
            $parserLogger = $this->loggerPool->createNewLogger('SPARQL');
            $p = new SPARQLPlusParser($parserLogger);
            $p->parse($q, $src);
            $infos = $p->getQueryInfos();
            $errors = $parserLogger->getEntries('error');
        }

        if ('infos' == $result_format) {
            return $infos;
        }

        $infos['result_format'] = $result_format;

        if (!isset($p) || 0 == \count($errors)) {
            $qt = $infos['query']['type'];
            $validTypes = ['select', 'ask', 'describe', 'construct', 'load', 'insert', 'delete', 'dump'];
            if (!\in_array($qt, $validTypes)) {
                throw new Exception('Unsupported query type "'.$qt.'"');
            }

            $cls = match (ucfirst($qt)) {
                'Ask' => AskQueryHandler::class,
                'Construct' => ConstructQueryHandler::class,
                'Describe' => DescribeQueryHandler::class,
                'Delete' => DeleteQueryHandler::class,
                'Insert' => InsertQueryHandler::class,
                'Load' => LoadQueryHandler::class,
                'Select' => SelectQueryHandler::class,
            };

            if (empty($cls)) {
                throw new Exception('Inalid query $type given.');
            }

            $queryHandlerLogger = $this->loggerPool->createNewLogger('QueryHandler');
            $result = (new $cls($this, $queryHandlerLogger))->runQuery($infos);

            $r = ['query_type' => $qt, 'result' => $result];
            $r['query_time'] = 0;

            /* query result */
            if ('raw' == $result_format) {
                return $r['result'];
            }
            if ('rows' == $result_format) {
                return $r['result']['rows'] ? $r['result']['rows'] : [];
            }
            if ('row' == $result_format) {
                return $r['result']['rows'] ? $r['result']['rows'][0] : [];
            }

            return $r;
        }

        return 0;
    }
}
