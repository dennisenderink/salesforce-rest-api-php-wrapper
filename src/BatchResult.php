<?php namespace Salesforce\Rest;

/**
 * The BatchResult class used for batch operations
 */
class BatchResult
{
    /**
     * @var BatchInfo
     */
    protected $batchInfo;

    /**
     * constructor
     *
     * @param array     $data
     * @param BatchInfo $batchInfo
     */
    public function __construct($data, BatchInfo $batchInfo)
    {
        foreach ($data as $k => $v) {
            $this->{$k} = $v;
        }

        $this->batchInfo = $batchInfo;
    }

    /**
     * Return the associated BatchInfo
     *
     * @return BatchInfo
     */
    public function getBatchInfo()
    {
        return $this->batchInfo;
    }
}
