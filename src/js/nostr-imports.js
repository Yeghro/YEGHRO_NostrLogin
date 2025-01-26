import NDK from "@nostr-dev-kit/ndk";
import { nip19 } from "nostr-tools";

(function($) {
    'use strict';

    class NostrImporter {
        constructor() {
            this.ndk = null;
            this.events = [];
            this.nip19 = nip19;
            
            // Enable NDK debugging in browser console
            if (typeof localStorage !== 'undefined') {
                localStorage.debug = 'ndk:*';
            }
            
            console.log('NostrImporter initialized');
            this.initializeListeners();
            
            this.defaultRelays = [
                "wss://purplepag.es",
                "wss://relay.nostr.band",
                "wss://relay.primal.net",
                "wss://relay.damus.io",
                "wss://nostr.wine"
            ];
        }

        async initializeNDK() {
            if (this.ndk) {
                console.log('NDK already initialized');
                return;
            }

            console.log('Initializing NDK with relays:', this.defaultRelays);
            
            try {
                // Create new NDK instance
                this.ndk = new NDK({
                    explicitRelayUrls: this.defaultRelays
                });

                // Connect to relays with timeout
                const connectPromise = this.ndk.connect();
                const connectTimeout = new Promise((_, reject) =>
                    setTimeout(
                        () => reject(new Error("Connection to relays timed out")),
                        15000
                    )
                );

                await Promise.race([connectPromise, connectTimeout]);
                console.log('Successfully connected to relays');
                
                // Log connected relays for debugging
                const connectedRelays = Array.from(this.ndk.pool.relays.values())
                    .map(relay => relay.url);
                console.log('Connected relays:', connectedRelays);
                
                return this.ndk;
            } catch (error) {
                console.error('NDK initialization failed:', error);
                throw error;
            }
        }

        initializeListeners() {
            console.log('Setting up event listeners');
            $('#nostr-import-form').on('submit', (e) => this.handlePreview(e));
            $('#start-import').on('click', () => this.handleImport());
        }

        async handlePreview(e) {
            e.preventDefault();
            console.log('Preview requested');
            
            const $form = $(e.target);
            const $preview = $('#preview-content');
            
            try {
                const pubkey = $('#author_pubkey').val();
                const dateFrom = $('#date_from').val();
                const dateTo = $('#date_to').val();

                // Convert npub to hex if needed
                let hexPubkey = pubkey;
                if (pubkey.startsWith('npub')) {
                    try {
                        const { type, data } = this.nip19.decode(pubkey);
                        if (type === 'npub') {
                            hexPubkey = data;
                        } else {
                            throw new Error('Invalid npub key');
                        }
                    } catch (error) {
                        console.error('Error decoding npub:', error);
                        $preview.html('Error: Invalid npub format. Please check your public key.');
                        return;
                    }
                }

                console.log('Using pubkey:', {
                    original: pubkey,
                    hex: hexPubkey
                });

                await this.initializeNDK();
                
                $preview.html('Fetching events...');
                
                const events = await this.fetchEvents(hexPubkey, dateFrom, dateTo);
                this.events = events;
                console.log(`Retrieved ${events.length} events`);

                if (events.length > 0) {
                    console.log('Sample event:', {
                        id: events[0].id,
                        pubkey: events[0].pubkey,
                        created_at: new Date(events[0].created_at * 1000).toISOString(),
                        content: events[0].content.substring(0, 100) + '...'
                    });
                }

                const previewHtml = this.generatePreview(events);
                $preview.html(previewHtml);
                
                $('#import-preview').show();
            } catch (error) {
                console.error('Preview generation failed:', error);
                $preview.html(`Error: ${error.message}`);
            }
        }

        async fetchEvents(pubkey, dateFrom, dateTo) {
            // Convert dates to timestamps
            const since = dateFrom ? new Date(dateFrom).getTime() / 1000 : undefined;
            const until = dateTo ? new Date(dateTo).getTime() / 1000 : undefined;

            const filter = {
                kinds: [1], // NIP-01 text notes
                authors: [pubkey],
                since,
                until
            };

            console.log('Fetching events with filter:', filter);

            try {
                const events = await this.ndk.fetchEvents(filter);
                const eventsArray = Array.from(events);
                
                if (eventsArray.length === 0) {
                    console.log('No events found for filter:', filter);
                }
                
                // Sort events by timestamp (newest first)
                eventsArray.sort((a, b) => b.created_at - a.created_at);
                
                console.log(`Successfully fetched ${eventsArray.length} events`);
                
                // Log event statistics
                const stats = {
                    totalEvents: eventsArray.length,
                    dateRange: {
                        earliest: eventsArray.length ? new Date(Math.min(...eventsArray.map(e => e.created_at * 1000))) : null,
                        latest: eventsArray.length ? new Date(Math.max(...eventsArray.map(e => e.created_at * 1000))) : null
                    },
                    averageContentLength: eventsArray.length ? 
                        eventsArray.reduce((acc, e) => acc + e.content.length, 0) / eventsArray.length : 0
                };
                console.log('Event statistics:', stats);

                return eventsArray;
            } catch (error) {
                console.error('Error fetching events:', error);
                throw error;
            }
        }

        generatePreview(events) {
            console.log('Generating preview for', events.length, 'events');
            
            if (events.length === 0) {
                return '<p>No events found</p>';
            }

            let html = `<p>Found ${events.length} events:</p><ul>`;
            
            events.forEach((event, index) => {
                const date = new Date(event.created_at * 1000).toLocaleString();
                const preview = event.content.substring(0, 100) + '...';
                
                // Log every 10th event for sampling
                if (index % 10 === 0) {
                    console.log(`Preview sample ${index}:`, {
                        date,
                        preview
                    });
                }
                
                html += `
                    <li>
                        <strong>${date}</strong><br>
                        ${preview}
                    </li>
                `;
            });

            html += '</ul>';
            return html;
        }

        async handleImport() {
            console.log('Starting import process');
            const $progress = $('#import-progress');
            const $status = $('#import-status');
            const total = this.events.length;
            
            console.log(`Preparing to import ${total} events`);
            $progress.show();
            
            let successCount = 0;
            let failureCount = 0;
            
            for (let i = 0; i < total; i++) {
                const event = this.events[i];
                console.log(`Processing event ${i + 1}/${total}:`, {
                    id: event.id,
                    created_at: new Date(event.created_at * 1000)
                });
                
                $status.text(`Importing event ${i + 1} of ${total}...`);
                
                try {
                    const response = await this.importEvent(event);
                    console.log(`Successfully imported event ${i + 1}:`, response);
                    successCount++;
                    
                    const progress = ((i + 1) / total) * 100;
                    $('.progress-bar-fill').css('width', `${progress}%`);
                } catch (error) {
                    console.error(`Failed to import event ${i + 1}:`, error);
                    failureCount++;
                    $status.html(`Error importing event: ${error.message}`);
                    return;
                }
            }
            
            console.log('Import complete. Summary:', {
                total,
                successCount,
                failureCount
            });
            
            $status.text(`Import complete! Successfully imported ${successCount} events.`);
        }

        async importEvent(event) {
            console.log('Preparing event for import:', event.id);
            
            // Create a clean event object with only the needed properties
            const cleanEvent = {
                id: event.id,
                pubkey: event.pubkey,
                created_at: event.created_at,
                kind: event.kind,
                content: event.content,
                tags: event.tags,
                sig: event.sig
            };
            
            console.log('Sending import request for event:', cleanEvent.id);
            
            try {
                const response = await $.ajax({
                    url: nostrImport.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'nostr_import_posts',
                        nonce: nostrImport.nonce,
                        event: JSON.stringify(cleanEvent)
                    }
                });
                
                if (response.success) {
                    console.log('Import successful for event', cleanEvent.id, ':', response.data);
                    return response.data;
                } else {
                    throw new Error(response.data.message || 'Import failed');
                }
            } catch (error) {
                console.error('Import request failed for event', cleanEvent.id, ':', error);
                throw new Error(error.responseJSON?.data?.message || error.message || 'Import failed');
            }
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        console.log('Document ready, initializing NostrImporter');
        new NostrImporter();
    });

})(jQuery);
