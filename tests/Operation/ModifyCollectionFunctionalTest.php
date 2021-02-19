<?php

namespace MongoDB\Tests\Operation;

use MongoDB\Operation\ModifyCollection;
use MongoDB\Operation\CreateCollection;
use MongoDB\Operation\CreateIndexes;

class ModifyCollectionFunctionalTest extends FunctionalTestCase
{
    /**
     * @group matrix-testing-server-4.2-driver-4.0-topology-sharded_cluster
     * @group matrix-testing-server-4.4-driver-4.0-topology-sharded_cluster
     * @group matrix-testing-server-5.0-driver-4.0-topology-sharded_cluster
     */
    public function testCollMod()
    {
        $operation = new CreateCollection($this->getDatabaseName(), $this->getCollectionName());
        $operation->execute($this->getPrimaryServer());

        $indexes = [['key' => ['lastAccess' => 1], 'expireAfterSeconds' => 3]];
        $createIndexes = new CreateIndexes($this->getDatabaseName(), $this->getCollectionName(), $indexes);
        $createIndexes->execute($this->getPrimaryServer());

        $modifyCollection = new ModifyCollection(
            $this->getDatabaseName(),
            $this->getCollectionName(),
            ['index' => ['keyPattern' => ['lastAccess' => 1], 'expireAfterSeconds' => 1000]],
            ['typeMap' => ['root' => 'array']]
        );
        $result = $modifyCollection->execute($this->getPrimaryServer());

        $this->assertSame(3, $result['expireAfterSeconds_old']);
        $this->assertSame(1000, $result['expireAfterSeconds_new']);
    }
}
