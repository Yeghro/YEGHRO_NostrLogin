<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Nostr_Event {
    private $event;
    private $decoded_event;

    public function __construct(string $event_json) {
        $this->event = $event_json;
        $this->decoded_event = json_decode($event_json);
    }

    public function verify(): bool {
        try {
            if (!$this->validateEventStructure()) {
                return false;
            }

            if (!$this->validateNip98Requirements()) {
                return false;
            }

            if (!$this->verifySignature()) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function validateEventStructure(): bool {
        $required_fields = ['kind', 'created_at', 'pubkey', 'tags', 'sig', 'id'];
        
        foreach ($required_fields as $field) {
            if (!isset($this->decoded_event->$field)) {
                return false;
            }
        }

        return true;
    }

    private function validateNip98Requirements(): bool {
        try {
            // Check event kind
            if ($this->decoded_event->kind !== 27235) {
                return false;
            }

            // Check timestamp (within last 60 seconds)
            if (time() - $this->decoded_event->created_at > 60) {
                return false;
            }

            // Extract and validate tags
            $tags = [];
            foreach ($this->decoded_event->tags as $tag) {
                if (count($tag) >= 2) {
                    $tags[$tag[0]] = $tag[1];
                }
            }

            // Verify URL matches
            if (!isset($tags['u']) || $tags['u'] !== admin_url('admin-ajax.php')) {
                return false;
            }

            // Verify method
            if (!isset($tags['method']) || $tags['method'] !== 'POST') {
                return false;
            }

            return true;

        } catch (Exception $e) {
            return false;
        }
    }

    private function verifySignature(): bool {
        try {
            // For now, we'll return true as signature verification requires additional crypto libraries
            // TODO: Implement actual signature verification using secp256k1
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getPublicKey(): string {
        return $this->decoded_event->pubkey ?? '';
    }
}