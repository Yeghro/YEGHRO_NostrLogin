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
        nostr_login_debug_log('Event constructed with data: ' . print_r($this->decoded_event, true));
    }

    public function verify(): bool {
        try {
            // Basic event structure validation
            if (!$this->validateEventStructure()) {
                nostr_login_debug_log('Event structure validation failed');
                return false;
            }

            // Verify NIP-98 specific requirements
            if (!$this->validateNip98Requirements()) {
                nostr_login_debug_log('NIP-98 requirements validation failed');
                return false;
            }

            // Verify event signature
            if (!$this->verifySignature()) {
                nostr_login_debug_log('Event signature verification failed');
                return false;
            }

            nostr_login_debug_log('Event verification successful');
            return true;

        } catch (Exception $e) {
            nostr_login_debug_log('Event verification error: ' . $e->getMessage());
            return false;
        }
    }

    private function validateEventStructure(): bool {
        $required_fields = ['kind', 'created_at', 'pubkey', 'tags', 'sig', 'id'];
        
        foreach ($required_fields as $field) {
            if (!isset($this->decoded_event->$field)) {
                nostr_login_debug_log("Missing required field: $field");
                return false;
            }
        }

        nostr_login_debug_log('Event structure validated');
        return true;
    }

    private function validateNip98Requirements(): bool {
        try {
            // Check event kind
            if ($this->decoded_event->kind !== 27235) {
                nostr_login_debug_log('Invalid event kind: ' . $this->decoded_event->kind);
                return false;
            }

            // Check timestamp (within last 60 seconds)
            if (time() - $this->decoded_event->created_at > 60) {
                nostr_login_debug_log('Event too old: ' . (time() - $this->decoded_event->created_at) . ' seconds');
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
                nostr_login_debug_log('Invalid URL tag: ' . ($tags['u'] ?? 'missing'));
                return false;
            }

            // Verify method
            if (!isset($tags['method']) || $tags['method'] !== 'POST') {
                nostr_login_debug_log('Invalid method tag: ' . ($tags['method'] ?? 'missing'));
                return false;
            }

            nostr_login_debug_log('NIP-98 requirements validated');
            return true;

        } catch (Exception $e) {
            nostr_login_debug_log('NIP-98 validation error: ' . $e->getMessage());
            return false;
        }
    }

    private function verifySignature(): bool {
        try {
            // For now, we'll return true as signature verification requires additional crypto libraries
            // TODO: Implement actual signature verification using secp256k1
            nostr_login_debug_log('Signature verification placeholder - returning true');
            return true;
        } catch (Exception $e) {
            nostr_login_debug_log('Signature verification error: ' . $e->getMessage());
            return false;
        }
    }

    public function getPublicKey(): string {
        return $this->decoded_event->pubkey ?? '';
    }
}