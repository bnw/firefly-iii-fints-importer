<?php

namespace App;

use GrumpyDictator\FFIIIApiSupport\Request\PostTagRequest;
use GrumpyDictator\FFIIIApiSupport\Request\PutTransactionRequest;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;

class PostImportTagger
{
    private string $firefly_url;
    private string $firefly_access_token;
    private string $tag_name;

    public function __construct(string $firefly_url, string $firefly_access_token, string $tag_name)
    {
        $this->firefly_url          = $firefly_url;
        $this->firefly_access_token = $firefly_access_token;
        $this->tag_name             = $tag_name;
    }

    /**
     * Creates the import tag and applies it to all successfully imported transaction groups.
     *
     * @param array $group_ids Maps group_id (int) => [ journal_id (int) => existing_tags (string[]) ]
     */
    public function apply(array $group_ids): void
    {
        if (empty($group_ids)) {
            return;
        }

        $this->createTag();

        foreach ($group_ids as $group_id => $journals) {
            $this->applyTagToGroup((int) $group_id, $journals);
        }
    }

    private function createTag(): void
    {
        $request = new PostTagRequest($this->firefly_url, $this->firefly_access_token);
        $request->setBody(array('tag' => $this->tag_name, 'date' => date('Y-m-d')));

        try {
            $response = $request->post();
            if ($response instanceof ValidationErrorResponse) {
                Logger::warn('Could not create import tag: ' . json_encode($response->errors->all()));
            }
        } catch (\Exception $e) {
            Logger::warn('Could not create import tag: ' . $e->getMessage());
        }
    }

    private function applyTagToGroup(int $group_id, array $journals): void
    {
        $transactions = array();
        foreach ($journals as $journal_id => $existing_tags) {
            $transactions[] = array(
                'transaction_journal_id' => $journal_id,
                'tags'                   => array_merge($existing_tags, array($this->tag_name)),
            );
        }

        $request = new PutTransactionRequest($this->firefly_url, $this->firefly_access_token, $group_id);
        $request->setBody(array(
            'apply_rules'   => false,
            'fire_webhooks' => false,
            'transactions'  => $transactions,
        ));

        try {
            $request->put();
        } catch (\Exception $e) {
            Logger::warn("Could not apply tag to transaction group {$group_id}: " . $e->getMessage());
        }
    }
}
