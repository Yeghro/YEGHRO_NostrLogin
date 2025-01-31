import NDK from "@nostr-dev-kit/ndk";
import { nip19 } from "nostr-tools";

// Add configuration object at the top
const CONFIG = {
    TIMEOUT_MS: 5000,
    DEFAULT_KIND: 1,
    MAX_PREVIEW_LENGTH: 100,
    MAX_EVENTS_PER_PAGE: 50,
    RATE_LIMIT_MS: 1000,
};

(function($) {
    'use strict';

    function handleError(error, context) {
        // Send error to server if WP_DEBUG is true
        if (window.nostrImport?.debug) {
            jQuery.post(nostrImport.ajax_url, {
                action: 'nostr_log_error',
                nonce: nostrImport.nonce,
                error: error.message,
                context: context
            });
        }
        
        // Show user-friendly notice
        jQuery('#nostr-import-form').prepend(`
            <div class="notice notice-error is-dismissible">
                <p>${error.message}</p>
            </div>
        `);
    }

    class NostrImporter {
        constructor() {
            this.ndk = null;
            this.events = [];
            this.nip19 = nip19;
            this.isLoading = false;
            this.currentPage = 1;
            this.lastRequestTime = 0;
            
            this.initializeListeners();
            
            this.defaultRelays = [
                "wss://purplepag.es",
                "wss://relay.nostr.band",
                "wss://relay.primal.net",
                "wss://relay.damus.io",
            ];
        }

        async initializeNDK() {
            if (this.ndk && this.ndk.pool.relays.size > 0) {
                return;
            }

            const configuredRelays = window.nostrImport?.relays;
            const relays = Array.isArray(configuredRelays) && configuredRelays.length > 0
                ? configuredRelays
                : this.defaultRelays;
            
            this.ndk = new NDK({
                explicitRelayUrls: relays,
                enableOutboxModel: false
            });

            try {
                await this.ndk.connect();
            } catch (error) {
                handleError(error, 'Failed to connect to Nostr relays');
                throw error;
            }
        }

        initializeListeners() {
            $('#nostr-import-form').on('submit', (e) => this.handlePreview(e));
            $('#start-import').on('click', () => this.handleImport());
        }

        // Add loading state handler
        setLoading(isLoading) {
            this.isLoading = isLoading;
            const $loadingIndicator = $('.nostr-loading-indicator');
            const $submitButton = $('#nostr-import-form button[type="submit"]');
            
            if (isLoading) {
                $loadingIndicator.show();
                $submitButton.prop('disabled', true);
            } else {
                $loadingIndicator.hide();
                $submitButton.prop('disabled', false);
            }
        }

        // Add rate limiting
        async rateLimitRequest() {
            const now = Date.now();
            const timeSinceLastRequest = now - this.lastRequestTime;
            
            if (timeSinceLastRequest < CONFIG.RATE_LIMIT_MS) {
                await new Promise(resolve => 
                    setTimeout(resolve, CONFIG.RATE_LIMIT_MS - timeSinceLastRequest)
                );
            }
            
            this.lastRequestTime = Date.now();
        }

        // Modified handlePreview with pagination
        async handlePreview(e) {
            e.preventDefault();
            
            if (this.isLoading) return;
            
            const $preview = $('#preview-content');
            let currentFilter = null;
            
            try {
                this.setLoading(true);
                await this.rateLimitRequest();
                
                currentFilter = await this.createFilterFromForm();
                await this.initializeNDK();
                
                $preview.html('<div class="notice notice-info"><p>Fetching events...</p></div>');
                
                const events = await this.fetchEventsWithTimeout(currentFilter);
                this.updatePreviewContent($preview, events, currentFilter);
                
                if (events.length > CONFIG.MAX_EVENTS_PER_PAGE) {
                    this.addPaginationControls($preview, events);
                }
                
            } catch (error) {
                console.error('Preview error:', error);
                this.handlePreviewError($preview, error, currentFilter);
            } finally {
                this.setLoading(false);
            }
        }

        async createFilterFromForm() {
            const pubkey = $('#author_pubkey').val();
            let hexPubkey = pubkey;
            if (pubkey.startsWith('npub')) {
                try {
                    const decoded = this.nip19.decode(pubkey);
                    hexPubkey = decoded.data;
                } catch (error) {
                    throw new Error('Invalid npub format');
                }
            }

            // Add metadata filter
            const metadataFilter = {
                authors: [hexPubkey],
                kinds: [0],
                limit: 1
            };

            // Fetch metadata first
            await this.initializeNDK();
            const metadataEvents = await this.ndk.fetchEvents(metadataFilter);
            const metadata = Array.from(metadataEvents)[0];
            
            if (metadata) {
                this.userMetadata = this.processMetadata(metadata);
            }

            const dateFrom = $('#date_from').val();
            const dateTo = $('#date_to').val();
            
            const filter = {
                authors: [hexPubkey],
                kinds: [1], // Regular notes
            };

            if (dateFrom) {
                filter.since = Math.floor(new Date(dateFrom).getTime() / 1000);
            }
            if (dateTo) {
                filter.until = Math.floor(new Date(dateTo).getTime() / 1000);
            }

            return filter;
        }

        processMetadata(event) {
            try {
                const metadata = JSON.parse(event.content);
                return {
                    name: metadata.name || '',
                    about: metadata.about || '',
                    picture: metadata.picture || '',
                    nip05: metadata.nip05 || '',
                    website: metadata.website || '',
                    banner: metadata.banner || '',
                    displayName: metadata.display_name || metadata.displayName || '',
                    raw: metadata
                };
            } catch (error) {
                console.error('Error parsing metadata:', error);
                return null;
            }
        }

        async fetchEventsWithTimeout(filter) {
            const subscription = this.ndk.subscribe(filter, { closeOnEose: true });
            const events = new Set();
            const tagFilter = $('input[name="tag_filter"]').val();
            const importComments = $('#import_comments').is(':checked');
            const commentEvents = new Map();

            // Step 1: First collect all the events
            try {
                await new Promise((resolve) => {
                    subscription.on('event', (event) => {
                        if (this.eventMatchesTagFilter(event, tagFilter)) {
                            events.add(event);
                        }
                    });

                    subscription.on('eose', () => {
                        resolve();
                    });

                    setTimeout(() => {
                        resolve();
                    }, CONFIG.TIMEOUT_MS);
                });

                // Step 2: If comments are enabled, fetch comments for all collected events
                if (importComments && events.size > 0) {
                    const eventIds = Array.from(events).map(e => e.id);
                    
                    const commentFilter = {
                        kinds: [1],
                        '#e': eventIds,
                        limit: 100
                    };

                    if (filter.since) {
                        commentFilter.since = filter.since;
                    }
                    if (filter.until) {
                        commentFilter.until = filter.until;
                    }

                    const commentSubscription = this.ndk.subscribe(commentFilter, { 
                        closeOnEose: true
                    });

                    await new Promise((resolve) => {
                        commentSubscription.on('event', (comment) => {
                            const parentEventId = comment.tags.find(tag => 
                                tag[0] === 'e' && eventIds.includes(tag[1])
                            )?.[1];

                            if (parentEventId) {
                                if (!commentEvents.has(parentEventId)) {
                                    commentEvents.set(parentEventId, new Set());
                                }
                                commentEvents.get(parentEventId).add(comment);
                            }
                        });

                        commentSubscription.on('eose', () => {
                            resolve();
                        });

                        setTimeout(() => {
                            resolve();
                        }, CONFIG.TIMEOUT_MS);
                    });
                }

                // After collecting comments, fetch metadata for all commenters
                const commenterPubkeys = new Set();
                commentEvents.forEach(comments => {
                    comments.forEach(comment => {
                        commenterPubkeys.add(comment.pubkey);
                    });
                });

                const commenterMetadata = new Map();
                if (commenterPubkeys.size > 0) {
                    const metadataFilter = {
                        kinds: [0],
                        authors: Array.from(commenterPubkeys),
                        limit: commenterPubkeys.size
                    };

                    const metadataSubscription = this.ndk.subscribe(metadataFilter, { 
                        closeOnEose: true 
                    });

                    await new Promise((resolve) => {
                        metadataSubscription.on('event', (event) => {
                            try {
                                const metadata = JSON.parse(event.content);
                                commenterMetadata.set(event.pubkey, metadata);
                            } catch (error) {
                                console.error(`Error parsing metadata for ${event.pubkey}:`, error);
                            }
                        });

                        metadataSubscription.on('eose', resolve);

                        setTimeout(resolve, CONFIG.TIMEOUT_MS);
                    });
                }

                // Process all events with their comments and metadata
                const processedEvents = Array.from(events).map(event => {
                    const processed = this.processEvent(event);
                    if (commentEvents.has(event.id)) {
                        const eventComments = Array.from(commentEvents.get(event.id))
                            .map(comment => {
                                const processedComment = this.processEvent(comment);
                                // Add commenter metadata if available
                                if (commenterMetadata.has(comment.pubkey)) {
                                    processedComment.metadata = commenterMetadata.get(comment.pubkey);
                                }
                                return processedComment;
                            });
                        processed.comments = eventComments;
                    }
                    return processed;
                });

                return processedEvents;

            } catch (error) {
                console.error('Error during event/comment fetch:', error);
                throw error;
            }
        }

        validateComment(comment, parentEvent) {
            try {
                const isValid = comment.tags.some(tag => {
                    return tag[0] === 'e' && tag[1] === parentEvent.id;
                });

                return isValid;
            } catch (error) {
                console.error('Error validating comment:', error);
                return false;
            }
        }

        eventMatchesTagFilter(event, tagFilter) {
            if (!tagFilter?.trim()) return true;
            
            const tagValue = tagFilter.trim().toLowerCase();
            const hasTTag = event.tags.some(tag => 
                tag[0] === 't' && tag[1].toLowerCase() === tagValue
            );
            const hasHashtag = event.content.toLowerCase().includes(`#${tagValue}`);
            
            return hasTTag || hasHashtag;
        }

        processEvent(event) {
            // Extract media URLs from content and tags
            const mediaInfo = this.extractMediaInfo(event);
            
            return {
                id: event.id,
                content: event.content,
                created_at: event.created_at,
                pubkey: event.pubkey,
                tags: event.tags,
                seen_on: Array.from(event.seenOn || []),
                media: mediaInfo // Add media information to the processed event
            };
        }

        // Simplified extractMediaInfo to just collect URLs
        extractMediaInfo(event) {
            const mediaInfo = {
                images: [],
                videos: []
            };

            // Check media tags
            event.tags.forEach(tag => {
                if (tag[0] === 'media' || tag[0] === 'video') {
                    const url = tag[1];
                    if (url && this.isVideoUrl(url)) {
                        mediaInfo.videos.push(url);
                    }
                }
            });

            // Check content for video URLs
            const contentVideos = this.extractVideoUrlsFromContent(event.content);
            contentVideos.forEach(url => {
                if (!mediaInfo.videos.includes(url)) {
                    mediaInfo.videos.push(url);
                }
            });

            return mediaInfo;
        }

        // Keep these helper methods for URL detection
        isVideoUrl(url) {
            const videoExtensions = [
                '.mp4', '.webm', '.ogg', '.mov', 
                '.m4v', '.mpeg', '.mpg', '.avi'
            ];
            
            const lowercaseUrl = url.toLowerCase();
            return videoExtensions.some(ext => lowercaseUrl.endsWith(ext));
        }

        extractVideoUrlsFromContent(content) {
            const videoUrls = new Set();
            const videoPattern = /(https?:\/\/[^\s<>"]+?\.(?:mp4|webm|ogg|mov|m4v|avi))/gi;
            
            let match;
            while ((match = videoPattern.exec(content)) !== null) {
                videoUrls.add(match[1]);
            }

            return Array.from(videoUrls);
        }

        // Modified updatePreviewContent to just show video URL count
        updatePreviewContent($preview, events, filter) {
            const startIndex = (this.currentPage - 1) * CONFIG.MAX_EVENTS_PER_PAGE;
            const endIndex = startIndex + CONFIG.MAX_EVENTS_PER_PAGE;
            const paginatedEvents = events.slice(startIndex, endIndex);

            const processedEvents = Array.from(paginatedEvents).map(event => {
                const processed = {
                    id: event.id,
                    content: event.comments && event.comments.length > 0 
                        ? this.formatPostWithComments(event, event.comments)
                        : event.content,
                    created_at: event.created_at,
                    pubkey: event.pubkey,
                    tags: event.tags,
                    seen_on: Array.from(event.seenOn || []),
                    media: event.media
                };
                return processed;
            });

            this.events = processedEvents;

            if (processedEvents.length === 0) {
                $preview.html(`
                    <div class="notice notice-warning">
                        <p>No posts found. Current filter:</p>
                        <pre>${JSON.stringify(filter, null, 2)}</pre>
                        ${$('input[name="tag_filter"]').val() ? `<p>Tag filter: "${$('input[name="tag_filter"]').val()}"</p>` : ''}
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

            // Add metadata section if available
            let metadataHtml = '';
            if (this.userMetadata) {
                metadataHtml = `
                    <div class="nostr-user-metadata">
                        <div class="profile-header">
                            ${this.userMetadata.banner ? `
                                <div class="profile-banner">
                                    <img src="${this.escapeHtml(this.userMetadata.banner)}" alt="Profile banner">
                                </div>
                            ` : ''}
                            <div class="profile-info">
                                ${this.userMetadata.picture ? `
                                    <div class="profile-picture">
                                        <img src="${this.escapeHtml(this.userMetadata.picture)}" alt="Profile picture">
                                    </div>
                                ` : ''}
                                <div class="profile-details">
                                    <h3>${this.escapeHtml(this.userMetadata.displayName || this.userMetadata.name)}</h3>
                                    ${this.userMetadata.nip05 ? `
                                        <p class="nip05">${this.escapeHtml(this.userMetadata.nip05)}</p>
                                    ` : ''}
                                    ${this.userMetadata.about ? `
                                        <p class="about">${this.escapeHtml(this.userMetadata.about)}</p>
                                    ` : ''}
                                    ${this.userMetadata.website ? `
                                        <p class="website">
                                            <a href="${this.escapeHtml(this.userMetadata.website)}" target="_blank" rel="noopener noreferrer">
                                                ${this.escapeHtml(this.userMetadata.website)}
                                            </a>
                                        </p>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }

            const previewHtml = `
                <div class="nostr-preview-container">
                    ${metadataHtml}
                    <div class="notice notice-success">
                        <p>Found ${events.length} posts (showing ${startIndex + 1}-${Math.min(endIndex, events.length)})</p>
                        <p>
                            <label>
                                <input type="checkbox" id="select-all-events" class="select-all-checkbox">
                                Select All Visible Posts
                            </label>
                        </p>
                    </div>
                    <div class="nostr-preview-list">
                        ${paginatedEvents.map(event => {
                            try {
                                const commentCount = event.comments?.length || 0;
                                const videoCount = event.media?.videos?.length || 0;
                                
                                return `
                                    <div class="nostr-preview-item">
                                        <div class="nostr-preview-checkbox">
                                            <input type="checkbox" 
                                                   class="event-checkbox" 
                                                   value="${event.id}" 
                                                   data-event-index="${events.indexOf(event)}">
                                        </div>
                                        <div class="nostr-preview-date">
                                            <strong>Date:</strong> ${new Date(event.created_at * 1000).toLocaleString()}
                                        </div>
                                        <div class="nostr-preview-content">
                                            <strong>Content:</strong> ${
                                                event.content ? 
                                                (event.content.substring(0, CONFIG.MAX_PREVIEW_LENGTH) + 
                                                 (event.content.length > CONFIG.MAX_PREVIEW_LENGTH ? '...' : '')) : 
                                                'No content'
                                            }
                                        </div>
                                        ${videoCount > 0 ? `
                                            <div class="nostr-preview-video-count">
                                                <strong>Videos:</strong> ${videoCount}
                                            </div>
                                        ` : ''}
                                        <div class="nostr-preview-comments">
                                            <strong>Comments:</strong> ${commentCount}
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
            `;
            
            $preview.html(previewHtml);
            
            this.initializeCheckboxes($preview);
            
            if (events.length > CONFIG.MAX_EVENTS_PER_PAGE) {
                this.addPaginationControls($preview, events);
            }
            
            $('#import-preview').show();

            // After updating the preview content, initialize media players
            this.initializeMediaPlayers($preview);
        }

        initializeCheckboxes($preview) {
            const $selectAll = $preview.find('#select-all-events');
            const $eventCheckboxes = $preview.find('.event-checkbox');

            // Handle "Select All" checkbox
            $selectAll.on('change', function() {
                $eventCheckboxes.prop('checked', $(this).is(':checked'));
            });

            // Update "Select All" when individual checkboxes change
            $eventCheckboxes.on('change', function() {
                const allChecked = $eventCheckboxes.length === $eventCheckboxes.filter(':checked').length;
                $selectAll.prop('checked', allChecked);
            });
        }

        handlePreviewError($preview, error, filter) {
            this.events = []; // Clear events on error
            $preview.html(`
                <div class="notice notice-error">
                    <p>Error: ${error.message}</p>
                    <p>Filter used:</p>
                    <pre>${JSON.stringify(filter, null, 2)}</pre>
                </div>
            `);
        }

        async handleImport() {
            if (!this.events || !this.events.length) {
                console.error('No events to import');
                $('#import-status').html(`
                    <div class="notice notice-error">
                        <p>No events to import. Please preview first.</p>
                    </div>
                `);
                return;
            }

            // Get selected event indexes
            const selectedIndexes = $('.event-checkbox:checked').map(function() {
                return $(this).data('event-index');
            }).get();

            if (selectedIndexes.length === 0) {
                $('#import-status').html(`
                    <div class="notice notice-error">
                        <p>Please select at least one post to import.</p>
                    </div>
                `);
                return;
            }

            const $progress = $('#import-progress');
            const $status = $('#import-status');
            const total = selectedIndexes.length;
            const categories = $('#post_category').val();
            
            $progress.show();
            
            let successCount = 0;
            let failureCount = 0;
            
            for (let i = 0; i < selectedIndexes.length; i++) {
                const event = this.events[selectedIndexes[i]];
                
                $status.text(`Importing event ${i + 1} of ${total}...`);
                
                try {
                    const response = await this.importEvent(event, categories);
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
            
            $status.html(`
                <div class="notice notice-success">
                    <p>Import complete! Successfully imported ${successCount} events.</p>
                    ${failureCount > 0 ? `<p>Failed to import ${failureCount} events.</p>` : ''}
                </div>
            `);

            // Initialize media players in the imported content
            this.initializeMediaPlayers($('#import-status'));
        }

        async importEvent(event, categories) {
            console.log('Preparing event for import:', event.id);
            
            // Create a clean event object with only the needed properties
            const cleanEvent = {
                id: event.id,
                pubkey: event.pubkey,
                created_at: event.created_at,
                kind: event.kind,
                content: event.content,
                tags: event.tags,
                sig: event.sig,
                comments: event.comments || [], // Add comments if they exist
                media: event.media
            };

            console.log(`Event ${event.id} has ${cleanEvent.comments.length} comments to import`);
            
            try {
                const response = await $.ajax({
                    url: nostrImport.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'nostr_import_posts',
                        nonce: nostrImport.nonce,
                        event: JSON.stringify(cleanEvent),
                        categories: JSON.stringify(categories)
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

        // Add this new method
        formatPostWithComments(post, comments) {
            let formattedContent = post.content;
            
            if (comments && comments.length > 0) {
                formattedContent += '\n\n<div class="nostr-comments-section">';
                formattedContent += `<h3>Comments (${comments.length})</h3>`;
                
                comments.forEach(comment => {
                    const authorName = comment.metadata?.name || 
                                     comment.metadata?.display_name || 
                                     comment.pubkey.substring(0, 8) + '...';
                                     
                    formattedContent += `
                        <div class="nostr-comment">
                            <div class="comment-author">${this.escapeHtml(authorName)}</div>
                            <div class="comment-date">
                                ${new Date(comment.created_at * 1000).toLocaleString()}
                            </div>
                            <div class="comment-content">${this.escapeHtml(comment.content)}</div>
                        </div>
                    `;
                });
                
                formattedContent += '</div>';
            }
            
            return formattedContent;
        }

        // Add this helper method for HTML escaping
        escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        // Add pagination controls
        addPaginationControls($container, events) {
            const totalPages = Math.ceil(events.length / CONFIG.MAX_EVENTS_PER_PAGE);
            
            const paginationHtml = `
                <div class="nostr-pagination">
                    <button class="button prev-page" ${this.currentPage === 1 ? 'disabled' : ''}>
                        Previous
                    </button>
                    <span class="page-info">Page ${this.currentPage} of ${totalPages}</span>
                    <button class="button next-page" ${this.currentPage === totalPages ? 'disabled' : ''}>
                        Next
                    </button>
                </div>
            `;
            
            $container.append(paginationHtml);
            
            // Add pagination event listeners
            $container.find('.prev-page').on('click', () => this.changePage('prev', events));
            $container.find('.next-page').on('click', () => this.changePage('next', events));
        }

        // Handle page changes
        changePage(direction, events) {
            if (direction === 'prev' && this.currentPage > 1) {
                this.currentPage--;
            } else if (direction === 'next' && this.currentPage < Math.ceil(events.length / CONFIG.MAX_EVENTS_PER_PAGE)) {
                this.currentPage++;
            }
            
            const $preview = $('#preview-content');
            this.updatePreviewContent($preview, events);
        }

        // Modified initializeMediaPlayers for better video handling
        initializeMediaPlayers($container) {
            $container.find('video').each(function() {
                const $video = $(this);
                
                if ($video.data('initialized')) {
                    return;
                }

                $video.on('error', function(e) {
                    const $parent = $video.parent();
                    $parent.append(`
                        <div class="video-error">
                            Error loading video. Please check the source.
                        </div>
                    `);
                    handleError(e, 'Video playback error');
                });

                $video.on('loadstart', function() {
                    $video.addClass('loading');
                }).on('canplay', function() {
                    $video.removeClass('loading');
                });

                $video.data('initialized', true);
            });
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        // Add loading indicator to DOM
        $('body').append('<div class="nostr-loading-indicator">Loading...</div>');
        
        // Initialize with error boundary
        try {
            new NostrImporter();
        } catch (error) {
            console.error('Failed to initialize NostrImporter:', error);
            $('#nostr-import-form').html(`
                <div class="notice notice-error">
                    <p>Failed to initialize Nostr importer: ${error.message}</p>
                </div>
            `);
        }
    });

})(jQuery);

