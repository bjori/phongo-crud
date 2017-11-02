<?php
/*
 * Copyright 2017 MongoDB, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace MongoDB\Operation;

use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Server;
use MongoDB\Driver\Exception\RuntimeException as DriverRuntimeException;
use MongoDB\Exception\InvalidArgumentException;
use MongoDB\Exception\UnexpectedValueException;
use MongoDB\Exception\UnsupportedException;
use stdClass;
use Traversable;

/**
 * Operation for the changeStream command.
 *
 * @api
 * @see \MongoDB\Collection::changeStream()
 * @see http://docs.mongodb.org/manual/reference/command/changeStream/
 */
class ChangeStream implements Executable
{
    private $databaseName;
    private $collectionName;
    private $resumeToken;
    private $pipeline;
    private $readPreference;
    private $options;

    /**
     * Constructs a changeStream command.
     *
     * Supported options:
     *
     *  * fullDocument (string): Allowed values: ‘default’, ‘updateLookup’.
     *    Defaults to ‘default’.  When set to ‘updateLookup’, the change
     *    notification for partial updates will include both a delta describing
     *    the changes to the document, as well as a copy of the entire document
     *    that was changed from some time after the change occurred. For forward
     *    compatibility, a driver MUST NOT raise an error when a user provides
     *    an unknown value. The driver relies on the server to validate this
     *    option.
     *
     *  * resumeAfter (document): Specifies the logical starting point for the
     *    new change stream.
     *
     *  * maxAwaitTimeMS (integer): The maximum amount of time for the server to
     *    wait on new documents to satisfy a change stream query.
     *
     *  * batchSize (integer): The number of documents to return per batch.
     *
     *    This option is sent only if the caller explicitly provides a value.
     *    The default is to not send a value.
     *
     *  * collation (document): Specifies a collation.
     *
     *    This option is sent only if the caller explicitly provides a value.
     *    The default is to not send a value.
     *
     * @param string         $databaseName   Database name
     * @param string         $collectionName Collection name
     * @param array          $pipeline       List of pipeline operations
     * @param array          $options        Command options
     * @throws InvalidArgumentException for parameter/option parsing errors
     */
    public function __construct($databaseName, $collectionName, array $pipeline, array $options = [])
    {
        $expectedIndex = 0;

        foreach ($pipeline as $i => $operation) {
            if ($i !== $expectedIndex) {
                throw new InvalidArgumentException(sprintf('$pipeline is not a list (unexpected index: "%s")', $i));
            }

            if ( ! is_array($operation) && ! is_object($operation)) {
                throw InvalidArgumentException::invalidType(sprintf('$pipeline[%d]', $i), $operation, 'array or object');
            }

            $expectedIndex += 1;
        }

        if (isset($options['fullDocument']) && ! is_string($options['fullDocument'])) {
            throw InvalidArgumentException::invalidType('"fullDocument" option', $options['fullDocument'], 'string');
        }

        if (isset($options['resumeAfter']) && ! is_array($options['resumeAfter']) && ! is_object($options['resumeAfter'])) {
            throw InvalidArgumentException::invalidType('"resumeAfter" option', $options['resumeAfter'], 'array or object');
        }

        if (isset($options['maxAwaitTimeMS']) && ! is_integer($options['maxAwaitTimeMS'])) {
            throw InvalidArgumentException::invalidType('"maxAwaitTimeMS" option', $options['maxAwaitTimeMS'], 'integer');
        }

        if (isset($options['batchSize']) && ! is_integer($options['batchSize'])) {
            throw InvalidArgumentException::invalidType('"batchSize" option', $options['batchSize'], 'integer');
        }

        if (isset($options['collation']) && ! is_array($options['collation']) && ! is_object($options['collation'])) {
            throw InvalidArgumentException::invalidType('"collation" option', $options['collation'], 'array or object');
        }

        $this->databaseName = (string) $databaseName;
        $this->collectionName = (string) $collectionName;

        $this->pipeline = $pipeline;

        $this->options = $options;
    }

    /**
     * Execute the operation.
     *
     * @see Executable::execute()
     * @param Server $server
     * @return Traversable
     * @throws UnexpectedValueException if the command response was malformed
     * @throws UnsupportedException if collation, read concern, or write concern is used and unsupported
     * @throws DriverRuntimeException for other driver errors (e.g. connection errors)
     */
    public function execute(Server $server)
    {
        $command = $this->createCommand($server);

        $cursor = $command->execute($server);

        return $cursor;
    }

    /**
     * Create the changeStream command.
     *
     * @param Server  $server
     * @param boolean $isCursorSupported
     * @return Command
     */
    private function createCommand(Server $server)
    {
        $changeStreamArray = ['$changeStream' => $this->options];
        array_unshift($this->pipeline, $changeStreamArray);

        $cmd = new Aggregate($this->databaseName, $this->collectionName, $this->pipeline);
        return $cmd;
    }
}
