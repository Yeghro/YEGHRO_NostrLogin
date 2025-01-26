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
            if (this.ndk && this.ndk.pool.relays.size > 0) {
                console.log('NDK already initialized');
                return;
            }

            const relays = nostrImport.relays;
            console.log('Initializing NDK with relays:', relays);

            this.ndk = new NDK({
                explicitRelayUrls: relays,
                enableOutboxModel: false // Disable outbox to only read events
            });

            try {
                await this.ndk.connect();
                console.log('Successfully connected to relays');
                const connectedRelays = Array.from(this.ndk.pool.relays.keys());
                console.log('Connected relays:', connectedRelays);
            } catch (error) {
                console.error('Failed to connect to relays:', error);
                throw new Error('Failed to connect to Nostr relays');
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
                // Convert npub to hex if needed
                let hexPubkey = pubkey;
                if (pubkey.startsWith('npub')) {
                    try {
                        const decoded = nip19.decode(pubkey);
                        hexPubkey = decoded.data;
                    } catch (error) {
                        throw new Error('Invalid npub format');
                    }
                }

                const dateFrom = $('#date_from').val();
                const dateTo = $('#date_to').val();
                const tagFilter = $('input[name="tag_filter"]').val();
                
                // Create filter object according to NIP-01
                const filter = {
                    authors: [hexPubkey],
                    kinds: [1], // Regular notes
                };

                // Add date filters only if they exist
                if (dateFrom) {
                    filter.since = Math.floor(new Date(dateFrom).getTime() / 1000);
                }
                if (dateTo) {
                    filter.until = Math.floor(new Date(dateTo).getTime() / 1000);
                }

                console.log('Using filter:', filter);

                // Initialize NDK if needed
                await this.initializeNDK();
                
                $preview.html('<div class="notice notice-info"><p>Fetching events...</p></div>');
                
                // Use NDK to fetch events with filter
                console.log('Fetching events with filter:', JSON.stringify(filter, null, 2));
                
                const subscription = this.ndk.subscribe(filter, { closeOnEose: true });
                let events = new Set();
                
                try {
                    await new Promise((resolve) => {
                        subscription.on('event', (event) => {
                            // If tag filter is specified, check if event has matching tag
                            if (tagFilter && tagFilter.trim()) {
                                const tagValue = tagFilter.trim().toLowerCase();
                                // Check both 't' tags and content for hashtags
                                const hasTTag = event.tags.some(tag => 
                                    tag[0] === 't' && tag[1].toLowerCase() === tagValue
                                );
                                const hasHashtag = event.content.toLowerCase().includes(`#${tagValue}`);
                                
                                if (hasTTag || hasHashtag) {
                                    console.log('Found matching tag in event:', event.id);
                                    events.add(event);
                                }
                            } else {
                                events.add(event);
                            }
                        });

                        subscription.on('eose', () => {
                            console.log(`EOSE received. Total events: ${events.size}`);
                            resolve();
                        });

                        // Set timeout
                        setTimeout(resolve, 5000);
                    });
                } finally {
                    console.log('Subscription completed');
                }

                const processedEvents = Array.from(events).map(event => ({
                    id: event.id,
                    content: event.content,
                    created_at: event.created_at,
                    pubkey: event.pubkey,
                    tags: event.tags,
                    seen_on: Array.from(event.seenOn || [])
                }));

                // Store the processed events for import
                this.events = processedEvents;

                console.log(`Processed ${processedEvents.length} events`);

                if (processedEvents.length === 0) {
                    $preview.html(`
                        <div class="notice notice-warning">
                            <p>No posts found. Current filter:</p>
                            <pre>${JSON.stringify(filter, null, 2)}</pre>
                            ${tagFilter ? `<p>Tag filter: "${tagFilter}"</p>` : ''}
                            <p>Connected relays:</p>
                            <ul>
                                ${Array.from(this.ndk.pool.relays.keys()).map(relay => 
                                    `<li>${relay}</li>`
                                ).join('')}
                            </ul>
                        </div>
                    `);
                    return;
                }

                // Generate preview HTML
                const previewHtml = `
                    <div class="nostr-preview-container">
                        <div class="notice notice-success">
                            <p>Found ${processedEvents.length} posts</p>
                        </div>
                        <div class="nostr-preview-list">
                            ${processedEvents.map(event => {
                                try {
                                    return `
                                        <div class="nostr-preview-item">
                                            <div class="nostr-preview-date">
                                                <strong>Date:</strong> ${new Date(event.created_at * 1000).toLocaleString()}
                                            </div>
                                            <div class="nostr-preview-content">
                                                <strong>Content:</strong> ${
                                                    event.content ? 
                                                    (event.content.substring(0, 100) + (event.content.length > 100 ? '...' : '')) : 
                                                    'No content'
                                                }
                                            </div>
                                            <div class="nostr-preview-tags">
                                                <strong>Tags:</strong> ${
                                                    Array.isArray(event.tags) ? 
                                                    event.tags.map(tag => `<span class="nostr-tag">${tag[0]}:${tag[1]}</span>`).join(', ') : 
                                                    'No tags'
                                                }
                                            </div>
                                        </div>
                                    `;
                                } catch (error) {
                                    console.error('Error rendering event:', error, event);
                                    return `
                                        <div class="error">
                                            Error rendering event: ${error.message}
                                        </div>
                                    `;
                                }
                            }).join('')}
                        </div>
                    </div>
                    <style>
                        .nostr-preview-container {
                            margin: 20px 0;
                            padding: 15px;
                            background: #fff;
                            border: 1px solid #ccd0d4;
                            border-radius: 4px;
                        }
                        .nostr-preview-list {
                            margin: 0;
                            padding: 0;
                        }
                        .nostr-preview-item {
                            margin-bottom: 15px;
                            padding: 10px;
                            border: 1px solid #e5e5e5;
                            background: #f8f9fa;
                            border-radius: 3px;
                        }
                        .nostr-preview-date,
                        .nostr-preview-content,
                        .nostr-preview-tags {
                            margin-bottom: 8px;
                            word-break: break-word;
                        }
                        .nostr-tag {
                            display: inline-block;
                            background: #e9ecef;
                            padding: 2px 6px;
                            border-radius: 3px;
                            margin: 2px;
                            font-size: 0.9em;
                        }
                        .notice {
                            margin: 0 0 15px 0;
                            padding: 10px;
                            border-left: 4px solid #72aee6;
                        }
                        .notice-success {
                            border-color: #46b450;
                            background: #ecf7ed;
                        }
                        .notice-error {
                            border-color: #dc3232;
                            background: #fbeaea;
                        }
                        .notice-warning {
                            border-color: #ffb900;
                            background: #fff8e5;
                        }
                        .notice-info {
                            border-color: #00a0d2;
                            background: #f0f6fc;
                        }
                    </style>
                `;
                
                console.log('Updating preview content');
                $preview.html(previewHtml);
                
                // Ensure preview container is visible
                $('#import-preview').show();
                
                console.log('Preview updated successfully');
            } catch (error) {
                console.error('Preview error:', error);
                this.events = []; // Clear events on error
                $preview.html(`
                    <div class="notice notice-error">
                        <p>Error: ${error.message}</p>
                        <p>Filter used:</p>
                        <pre>${JSON.stringify(filter, null, 2)}</pre>
                    </div>
                `);
            }
        }

        async fetchEvents(pubkey, dateFrom, dateTo, tagFilter) {
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
            
            if (!this.events || !this.events.length) {
                console.error('No events to import');
                $('#import-status').html(`
                    <div class="notice notice-error">
                        <p>No events to import. Please preview first.</p>
                    </div>
                `);
                return;
            }

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
