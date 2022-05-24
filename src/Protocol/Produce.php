<?php
namespace Kafka\Protocol;

class Produce extends Protocol
{

    /**
     * produce request encode
     *
     * @param array $payloads
     * @access public
     * @return string
     */
    public function encode($payloads)
    {
        if (! isset($payloads['data'])) {
            throw new \Kafka\Exception\Protocol('given procude data invalid. `data` is undefined.');
        }

        if (! isset($payloads['required_ack'])) {
            // default server will not send any response
            // (this is the only case where the server will not reply to a request)
            $payloads['required_ack'] = 0;
        }

        if (! isset($payloads['timeout'])) {
            $payloads['timeout'] = 100; // default timeout 100ms
        }

        $header = $this->requestHeader('kafka-php', 0, self::PRODUCE_REQUEST);
        $data   = self::pack(self::BIT_B16, $payloads['required_ack']);
        $data  .= self::pack(self::BIT_B32, $payloads['timeout']);
        $data  .= self::encodeArray($payloads['data'], [$this, 'encodeProcudeTopic'], self::COMPRESSION_NONE);
        $data   = self::encodeString($header . $data, self::PACK_INT32);

        return $data;
    }

    /**
     * decode produce response
     *
     * @access public
     * @return array
     */
    public function decode($data)
    {
        $offset       = 0;
        $version      = $this->getApiVersion(self::PRODUCE_REQUEST);
        $ret          = $this->decodeArray(substr($data, $offset), [$this, 'produceTopicPair'], $version);
        $offset      += $ret['length'];
        $throttleTime = 0;
        if ($version == self::API_VERSION2) {
            $throttleTime = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        }
        return ['throttleTime' => $throttleTime, 'data' => $ret['data']];
    }

    /**
     * encode message set
     * N.B., MessageSets are not preceded by an int32 like other array elements
     * in the protocol.
     *
     * @param array $messages
     * @param int $compression
     * @return string
     * @static
     * @access public
     */
    protected function encodeMessageSet($messages, $compression = self::COMPRESSION_NONE)
    {
        if (! is_array($messages)) {
            $messages = [$messages];
        }

        $data = '';
        $next = 0;
        foreach ($messages as $message) {
            $tmpMessage = $this->encodeMessage($message, $compression);

            // int64 -- message offset     Message
            //This is the offset used in kafka as the log sequence number. When the producer is sending non compressed messages, it can set the offsets to anything. When the producer is sending compressed messages, to avoid server side recompression, each compressed message should have offset starting from 0 and increasing by one for each inner message in the compressed message. (see more details about compressed messages in Kafka below)
            $data .= self::pack(self::BIT_B64, $next) . self::encodeString($tmpMessage, self::PACK_INT32);
            $next++;
        }
        return $data;
    }

    /**
     * encode signal message
     *
     * @param string $message
     * @param int $compression
     * @return string
     * @static
     * @access protected
     */
    protected function encodeMessage($message, $compression = self::COMPRESSION_NONE)
    {
        // int8 -- magic  int8 -- attribute
        $version = $this->getApiVersion(self::PRODUCE_REQUEST);
        $magic   = ($version == self::API_VERSION2) ? self::MESSAGE_MAGIC_VERSION1 : self::MESSAGE_MAGIC_VERSION0;
        $data    = self::pack(self::BIT_B8, $magic);
        $data   .= self::pack(self::BIT_B8, $compression);

        if ($version == self::API_VERSION2) {
            $timestamp = round(microtime(true) * 1000);
            $data   .= self::pack(self::BIT_B64, $timestamp);
        }

        $key = '';
        if (is_array($message)) {
            $key     = $message['key'];
            $message = $message['value'];
        }
        // message key
        $data .= self::encodeString($key, self::PACK_INT32);

        // message value
        $data .= self::encodeString($message, self::PACK_INT32, $compression);

        $crc = crc32($data);

        // int32 -- crc code  string data
        $message = self::pack(self::BIT_B32, $crc) . $data;

        return $message;
    }

    /**
     * encode signal part
     *
     * @param $values
     * @param $compression
     * @return string
     * @internal param $partions
     * @access protected
     */
    protected function encodeProcudePartion($values, $compression)
    {
        if (! isset($values['partition_id'])) {
            throw new \Kafka\Exception\Protocol('given produce data invalid. `partition_id` is undefined.');
        }

        if (! isset($values['messages']) || empty($values['messages'])) {
            throw new \Kafka\Exception\Protocol('given produce data invalid. `messages` is undefined.');
        }

        $data  = self::pack(self::BIT_B32, $values['partition_id']);
        $data .= self::encodeString($this->encodeMessageSet($values['messages'], $compression), self::PACK_INT32);

        return $data;
    }

    /**
     * encode signal topic
     *
     * @param $values
     * @param $compression
     * @return string
     * @internal param $partions
     * @access protected
     */
    protected function encodeProcudeTopic($values, $compression)
    {
        if (! isset($values['topic_name'])) {
            throw new \Kafka\Exception\Protocol('given produce data invalid. `topic_name` is undefined.');
        }

        if (! isset($values['partitions']) || empty($values['partitions'])) {
            throw new \Kafka\Exception\Protocol('given produce data invalid. `partitions` is undefined.');
        }

        $topic      = self::encodeString($values['topic_name'], self::PACK_INT16);
        $partitions = self::encodeArray($values['partitions'], [$this, 'encodeProcudePartion'], $compression);

        return $topic . $partitions;
    }

    /**
     * decode produce topic pair response
     *
     * @access protected
     * @return array
     */
    protected function produceTopicPair($data, $version)
    {
        $offset    = 0;
        $topicInfo = $this->decodeString($data, self::BIT_B16);
        $offset   += $topicInfo['length'];
        $ret       = $this->decodeArray(substr($data, $offset), [$this, 'producePartitionPair'], $version);
        $offset   += $ret['length'];

        return ['length' => $offset, 'data' => [
            'topicName' => $topicInfo['data'],
            'partitions'=> $ret['data'],
        ]];
    }

    /**
     * decode produce partition pair response
     *
     * @access protected
     * @return array
     */
    protected function producePartitionPair($data, $version)
    {
        $offset          = 0;
        $partitionId     = self::unpack(self::BIT_B32, substr($data, $offset, 4));
        $offset         += 4;
        $errorCode       = self::unpack(self::BIT_B16_SIGNED, substr($data, $offset, 2));
        $offset         += 2;
        $partitionOffset = self::unpack(self::BIT_B64, substr($data, $offset, 8));
        $offset         += 8;
        $timestamp       = 0;
        if ($version == self::API_VERSION2) {
            $timestamp = self::unpack(self::BIT_B64, substr($data, $offset, 8));
            $offset   += 8;
        }

        return [
            'length' => $offset,
            'data'   => [
                'partition' => $partitionId,
                'errorCode' => $errorCode,
                'offset' => $offset,
                'timestamp' => $timestamp,
            ]
        ];
    }
}
