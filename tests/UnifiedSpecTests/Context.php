<?php

namespace MongoDB\Tests\UnifiedSpecTests;

use LogicException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use stdClass;
use function array_diff_key;
use function array_keys;
use function getenv;
use function implode;
use function mt_rand;
use function uniqid;
use const PHP_URL_HOST;

/**
 * Execution context for spec tests.
 *
 * This object tracks state that would be difficult to store on the test itself
 * due to the design of PHPUnit's data providers and setUp/tearDown methods.
 */
final class Context
{
    /** @var EntityMap */
    private $entityMap;

    /** @var EventObserver[] */
    private $eventObserversByClient = [];

    /** @var Client */
    private $internalClient;

    /** @var string */
    private $uri;

    public function __construct(Client $internalClient, string $uri)
    {
        $this->entityMap = new EntityMap;
        $this->internalClient = $internalClient;
        $this->uri = $uri;
    }

    /**
     * Create entities for "createEntities".
     *
     * @param array $createEntities
     */
    public function createEntities(array $entities)
    {
        foreach ($entities as $entity) {
            assertIsObject($entity);
            $entity = (array) $entity;
            assertCount(1, $entity);

            $type = key($entity);
            $def = current($entity);
            assertIsObject($def);

            $id = $def->id ?? null;
            assertIsString($id);
            assertArrayNotHasKey($id, $this->entityMap);

            switch ($type) {
                case 'client':
                    $this->entityMap[$id] = $this->createClient($def);
                    break;

                case 'database':
                    $this->entityMap[$id] = $this->createDatabase($def);
                    break;

                case 'collection':
                    $this->entityMap[$id] = $this->createCollection($def);
                    break;

                default:
                    throw new LogicException('Unsupported entity type: ' . $type);
            }
        }
    }

    public function getEntityMap(): EntityMap
    {
        return $this->entityMap;
    }
    
    public function getInternalClient(): Client
    {
        return $this->internalClient;
    }

    public static function prepareOperationArguments(array $args): array
    {
        if (array_key_exists('readConcern', $args)) {
            assertIsObject($args['readConcern']);
            $args['readConcern'] = self::prepareReadConcern($args['readConcern']);
        }

        if (array_key_exists('readPreference', $args)) {
            assertIsObject($args['readPreference']);
            $args['readPreference'] = self::prepareReadPreference($args['readPreference']);
        }

        if (array_key_exists('session', $args)) {
            assertIsString($args['session']);
            assertArrayHasKey($args['session'], $this->entityMap);
            $session = $this->entityMap[$args['session']];
            assertInstanceOf(Session::class, $session);
            $args['session'] = $session;
        }

        if (array_key_exists('writeConcern', $args)) {
            assertIsObject($args['writeConcern']);
            $args['writeConcern'] = self::prepareWriteConcern($args['writeConcern']);
        }

        return $args;
    }

    public function startEventObservers()
    {
        foreach ($this->eventObserversByClient as $eventObserver) {
            $eventObserver->start();
        }
    }

    public function stopEventObservers()
    {
        foreach ($this->eventObserversByClient as $eventObserver) {
            $eventObserver->stop();
        }
    }

    private static function assertHasOnlyKeys($arrayOrObject, array $keys)
    {
        assertThat($arrayOrObject, logicalOr(IsType('array'), IsType('object')));
        $diff = array_diff_key((array) $arrayOrObject, array_fill_keys($keys, 1));
        assertEmpty($diff, 'Unsupported keys: ' . implode(',', array_keys($diff)));
    }

    private function createClient(stdClass $o): Client
    {
        self::assertHasOnlyKeys($o, ['id', 'uriOptions', 'useMultipleMongoses', 'observeEvents', 'ignoreCommandMonitoringEvents']);

        $useMultipleMongoses = $o->useMultipleMongoses ?? null;
        $observeEvents = $o->observeEvents ?? null;
        $ignoreCommandMonitoringEvents = $o->ignoreCommandMonitoringEvents ?? [];

        $uri = $this->uri;

        if (isset($useMultipleMongoses)) {
            assertIsBool($useMultipleMongoses);

            if ($useMultipleMongoses) {
                self::requireMultipleMongoses($uri);
            } else {
                $uri = self::removeMultipleMongoses($uri);
            }
        }

        $uriOptions = [];

        if (isset($o->uriOptions)) {
            assertIsObject($o->uriOptions);
            /* TODO: If readPreferenceTags is set, assert it is an array of
             * strings and convert to an array of documents expected by the
             * PHP driver. */
            $uriOptions = (array) $o->uriOptions;
        }

        if (isset($observeEvents)) {
            assertIsArray($observeEvents);
            assertIsArray($ignoreCommandMonitoringEvents);

            $this->eventObserversByClient[$o->id] = new EventObserver($observeEvents, $ignoreCommandMonitoringEvents);
        }

        return new Client($uri, $uriOptions);
    }

    private function createCollection(stdClass $o): Collection
    {
        self::assertHasOnlyKeys($o, ['id', 'database', 'collectionName', 'collectionOptions']);

        $collectionName = $o->collectionName ?? null;
        $database = $o->database ?? null;

        assertIsString($collectionName);
        assertIsString($database);
        assertArrayHasKey($database, $this->entityMap);

        $database = $this->entityMap[$database];
        assertInstanceOf(Database::class, $database);

        $options = [];

        if (isset($o->collectionOptions)) {
            assertIsObject($o->collectionOptions);
            $options = self::prepareCollectionOrDatabaseOptions((array) $o->collectionOptions);
        }

        return $database->selectCollection($o->collectionName, $options);
    }

    private function createDatabase(stdClass $o): Database
    {
        self::assertHasOnlyKeys($o, ['id', 'client', 'databaseName', 'databaseOptions']);

        $databaseName = $o->databaseName ?? null;
        $client = $o->client ?? null;

        assertIsString($databaseName);
        assertIsString($client);
        assertArrayHasKey($client, $this->entityMap);

        $client = $this->entityMap[$client];
        assertInstanceOf(Client::class, $client);

        $options = [];

        if (isset($o->databaseOptions)) {
            assertIsObject($o->databaseOptions);
            $options = self::prepareCollectionOrDatabaseOptions((array) $o->databaseOptions);
        }

        return $client->selectDatabase($databaseName, $options);
    }

    private static function prepareCollectionOrDatabaseOptions(array $options): array
    {
        self::assertHasOnlyKeys($options, ['readConcern', 'readPreference', 'writeConcern']);

        if (array_key_exists('readConcern', $options)) {
            assertIsObject($options['readConcern']);
            $options['readConcern'] = self::createReadConcern($options['readConcern']);
        }

        if (array_key_exists('readPreference', $options)) {
            assertIsObject($options['readPreference']);
            $options['readPreference'] = self::createReadPreference($options['readPreference']);
        }

        if (array_key_exists('writeConcern', $options)) {
            assertIsObject($options['writeConcern']);
            $options['writeConcern'] = self::createWriteConcern($options['writeConcern']);
        }

        return $options;
    }

    private static function createReadConcern(stdClass $o): ReadConcern
    {
        self::assertHasOnlyKeys($o, ['level']);

        $level = $o->level ?? null;
        assertIsString($level);

        return new ReadConcern($level);
    }

    private static function createReadPreference(stdClass $o): ReadPreference
    {
        self::assertHasOnlyKeys($o, ['mode', 'tagSets', 'maxStalenessSeconds', 'hedge']);

        $mode = $o->mode ?? null;
        $tagSets = $o->tagSets ?? null;
        $maxStalenessSeconds = $o->maxStalenessSeconds ?? null;
        $hedge = $o->hedge ?? null;

        assertIsString($mode);

        if (isset($tagSets)) {
            assertIsArray($tagSets);
            assertContains('object', $tagSets);
        }

        $options = [];

        if (isset($maxStalenessSeconds)) {
            assertIsInt($maxStalenessSeconds);
            $options['maxStalenessSeconds'] = $maxStalenessSeconds;
        }

        if (isset($hedge)) {
            assertIsObject($hedge);
            $options['hedge'] = $hedge;
        }

        return new ReadPreference($mode, $tagSets, $options);
    }

    private static function createWriteConcern(stdClass $o): WriteConcern
    {
        self::assertHasOnlyKeys($o, ['w', 'wtimeoutMS', 'journal']);

        $w = $o->w ?? -2 /* MONGOC_WRITE_CONCERN_W_DEFAULT */;
        $wtimeoutMS = $o->wtimeoutMS ?? 0;
        $journal = $o->journal ?? null;

        assertThat($w, logicalOr(new IsType('int'), new IsType('string')));
        assertIsInt($wtimeoutMS);

        $args = [$w, $wtimeoutMS];

        if (isset($journal)) {
            assertIsBool($journal);
            $args[] = $journal;
        }

        return new WriteConcern(...$args);
    }

    /**
     * Removes mongos hosts beyond the first if the URI refers to a sharded
     * cluster. Otherwise, the URI is returned as-is.
     */
    private static function removeMultipleMongoses(string $uri): string
    {
        assertStringStartsWith('mongodb://', $uri);

        $manager = new Manager($uri);

        // Nothing to do if the URI does not refer to a sharded cluster
        if ($manager->selectServer(new ReadPreference(ReadPreference::PRIMARY))->getType() !== Server::TYPE_MONGOS) {
            return $uri;
        }

        $parts = parse_url($uri);

        assertIsArray($parts);

        $hosts = explode(',', $parts['host']);

        // Nothing to do if the URI already has a single mongos host
        if (count($hosts) === 1) {
            return $uri;
        }

        // Re-append port to last host
        if (isset($parts['port'])) {
            $hosts[count($hosts) - 1] .= ':' . $parts['port'];
        }

        $singleHost = $hosts[0];
        $multipleHosts = implode(',', $hosts);

        $pos = strpos($uri, $multipleHosts);

        assertNotFalse($pos);

        return substr_replace($uri, $singleHost, $pos, strlen($multipleHosts));
    }

    /**
     * Requires multiple mongos hosts if the URI refers to a sharded cluster.
     */
    private static function requireMultipleMongoses(string $uri)
    {
        assertStringStartsWith('mongodb://', $uri);

        $manager = new Manager($uri);

        // Nothing to do if the URI does not refer to a sharded cluster
        if ($manager->selectServer(new ReadPreference(ReadPreference::PRIMARY))->getType() !== Server::TYPE_MONGOS) {
            return;
        }

        assertStringContains(',', parse_url($uri, PHP_URL_HOST));
    }
}
