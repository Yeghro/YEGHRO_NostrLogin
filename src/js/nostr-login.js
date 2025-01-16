import NDK from "@nostr-dev-kit/ndk";
import { NDKNip07Signer } from "@nostr-dev-kit/ndk";
import { getPublicKey, finalizeEvent, verifyEvent } from "nostr-tools/pure";
import { nip19, nip98 } from "nostr-tools";

(function ($) {
  $(document).ready(function () {
    // console.log("Nostr login script loaded");

    const TIMEOUT_DURATION = 15000; // 15 seconds timeout

    let ndk = new NDK({
      explicitRelayUrls: [
        "wss://purplepag.es",
        "wss://relay.nostr.band",
        "wss://relay.primal.net",
        "wss://relay.damus.io",
        // Add more relay URLs as needed
      ],
    });

    var $loginForm = $("#loginform");
    var $nostrToggle = $("#nostr_login_toggle");
    var $nostrField = $(".nostr-login-field");
    var $nostrButtons = $(".nostr-login-buttons");
    var $submitButton = $("#nostr-wp-submit"); // Changed from "#wp-submit"
    var $useExtensionButton = $("#use_nostr_extension");
    var $originalSubmitButton = $("#wp-submit"); // Add this line to keep a reference to the original submit button
    var $originalFields = $loginForm
      .children()
      .not(".nostr-login-container, .nostr-login-field, .nostr-login-buttons")
      .detach();

    // Apply initial styles
    applyToggleStyles();

    function applyToggleStyles() {
      var $toggleContainer = $nostrToggle.closest(".nostr-login-container");
      $toggleContainer.css({
        "margin-bottom": "20px",
        position: "relative",
        display: "flex",
        "align-items": "center",
      });

      $nostrToggle.css({
        opacity: "0",
        width: "0",
        height: "0",
        position: "absolute",
      });

      var $slider = $('<span class="nostr-toggle-slider"></span>');
      $slider.css({
        position: "relative",
        cursor: "pointer",
        width: "60px",
        height: "34px",
        "background-color": "#ccc",
        transition: ".4s",
        "border-radius": "34px",
        display: "inline-block",
        "margin-right": "10px",
      });

      $slider.append(
        $("<span></span>").css({
          position: "absolute",
          content: '""',
          height: "26px",
          width: "26px",
          left: "4px",
          bottom: "4px",
          "background-color": "white",
          transition: ".4s",
          "border-radius": "50%",
        })
      );

      $nostrToggle.after($slider);

      $toggleContainer.find(".nostr-toggle-label span").css({
        "vertical-align": "middle",
      });
    }

    function updateToggleState() {
      var isChecked = $nostrToggle.prop("checked");
      $nostrToggle
        .next(".nostr-toggle-slider")
        .css("background-color", isChecked ? "#2196F3" : "#ccc");
      $nostrToggle
        .next(".nostr-toggle-slider")
        .find("span")
        .css("transform", isChecked ? "translateX(26px)" : "none");
    }

    function toggleNostrLogin() {
      var isNostrLogin = $nostrToggle.prop("checked");
      // console.log("Nostr login " + (isNostrLogin ? "enabled" : "disabled"));

      $loginForm
        .children()
        .not(".nostr-login-container, .nostr-login-field, .nostr-login-buttons")
        .remove();

      if (isNostrLogin) {
        $nostrField.show();
        $nostrButtons.show();
        $loginForm.off("submit").on("submit", handleNostrSubmit);
      } else {
        $nostrField.hide();
        $nostrButtons.hide();
        $loginForm.append($originalFields.clone());
        $submitButton.val("Log In");
        $loginForm.off("submit");
      }

      updateToggleState();
    }

    $nostrToggle.on("change", toggleNostrLogin);
    $useExtensionButton.on("click", handleNostrExtension);

    // Initial setup
    toggleNostrLogin();

    function uint8ArrayToHex(uint8Array) {
      return Array.from(uint8Array)
        .map((b) => b.toString(16).padStart(2, "0"))
        .join("");
    }

    async function handleNostrSubmit(e) {
      e.preventDefault();
      let privateKey = $("#nostr_private_key").val();

      try {
        if (privateKey.startsWith("nsec")) {
          try {
            const { type, data } = nip19.decode(privateKey);
            if (type === "nsec") {
              privateKey = uint8ArrayToHex(data);
            } else {
              throw new Error("Invalid nsec key");
            }
          } catch (error) {
            // console.error("Error decoding nsec key:", error);
            alert(
              "Invalid nsec key format. Please check your private key and try again."
            );
            return;
          }
        }

        if (!/^[0-9a-fA-F]{64}$/.test(privateKey)) {
          throw new Error("Invalid private key format");
        }

        await performNostrLogin(privateKey);
      } catch (error) {
        // console.error("Nostr login error:", error);
        alert(
          "Failed to log in with Nostr. Please check your private key and try again."
        );
      }
    }

    async function handleNostrExtension(e) {
      e.preventDefault();

      try {
        const nip07Signer = new NDKNip07Signer();
        let user = await nip07Signer.user();
        // console.log("nip07 fetched user:", user);
        const publicKey = user.pubkey;
        await performNostrLogin(null, publicKey, nip07Signer);
      } catch (error) {
        // console.error("Nostr extension error:", error);
        alert(
          "Failed to use Nostr extension. Please make sure you have a compatible extension installed and try again."
        );
      }
    }

    async function performNostrLogin(
      privateKey = null,
      publicKey = null,
      signer = null
    ) {
      try {
        if (!publicKey) {
          publicKey = getPublicKey(privateKey);
        }

        // Connect to relays with timeout
        const connectPromise = ndk.connect();
        const connectTimeout = new Promise((_, reject) =>
          setTimeout(
            () => reject(new Error("Connection to relays timed out")),
            TIMEOUT_DURATION
          )
        );

        try {
          await Promise.race([connectPromise, connectTimeout]);
        } catch (error) {
          alert("Failed to connect to Nostr relays. Please try again later.");
          return;
        }

        // Create user object and fetch profile
        let user = ndk.getUser({ pubkey: publicKey });
        if (signer) {
          user.signer = signer;
        }

        // Fetch profile with timeout
        try {
          await Promise.race([
            user.fetchProfile(),
            new Promise((_, reject) => setTimeout(() => reject(new Error("Profile fetch timeout")), TIMEOUT_DURATION))
          ]);
        } catch (error) {
          console.warn("Failed to fetch user profile:", error);
        }

        const metadata = user.profile || {};

        // Generate NIP-98 auth token using nostr-tools
        const authToken = await nip98.getToken(
          nostr_login_ajax.ajax_url,
          'POST',
          privateKey ? 
            (event) => finalizeEvent(event, privateKey) : 
            (event) => window.nostr.signEvent(event)
        );

        // Send login request
        $.ajax({
          url: nostr_login_ajax.ajax_url,
          type: 'POST',
          data: {
            action: 'nostr_login',
            public_key: publicKey,
            metadata: JSON.stringify(metadata),
            authtoken: authToken,
            nonce: nostr_login_ajax.nonce
          },
          success: function(response) {
            if (response.success) {
              window.location.href = response.data.redirect;
            } else {
              alert(response.data.message);
            }
          },
          error: function(xhr, status, error) {
            console.error('Login error:', error);
            alert('An error occurred during login. Please try again.');
          }
        });
      } catch (error) {
        console.error('Nostr login error:', error);
        throw error;
      }
    }

    async function handleNostrSync(e) {
        e.preventDefault();
        const $feedback = $('#nostr-connect-feedback');
        const $button = $(e.target);
        
        try {
            // Disable button and show loading state
            $button.prop('disabled', true);
            $feedback.removeClass('notice-error notice-success').addClass('notice-info')
                .html('Connecting to Nostr...').show();

            // Check for extension
            if (typeof window.nostr === 'undefined') {
                throw new Error('Nostr extension not found. Please install a Nostr extension.');
            }

            const nip07Signer = new NDKNip07Signer();
            const user = await nip07Signer.user();
            
            if (!user || !user.pubkey) {
                throw new Error('Failed to get public key from extension.');
            }

            // Update feedback
            $feedback.html('Connecting to relays...');

            // Ensure NDK is connected
            if (!ndk.connected) {
                try {
                    await ndk.connect();
                    console.log("connected to relays", ndk);
                } catch (error) {
                    throw new Error('Failed to connect to relays. Please try again.');
                }
            }

            $feedback.html('Fetching Nostr profile...');

            // Create NDK user and fetch profile with explicit error handling
            const ndkUser = ndk.getUser({ pubkey: user.pubkey });
            ndkUser.signer = nip07Signer;
            
            try {
                // Set a timeout for profile fetching
                const profilePromise = ndkUser.fetchProfile();
                const timeoutPromise = new Promise((_, reject) => 
                    setTimeout(() => reject(new Error('Profile fetch timeout')), 15000)
                );
                
                await Promise.race([profilePromise, timeoutPromise]);
                
                if (!ndkUser.profile) {
                    console.warn('No profile data found, proceeding with public key only');
                }
            } catch (error) {
                console.warn('Profile fetch failed:', error);
                // Continue with just the public key if profile fetch fails
            }

            // Prepare metadata with at least the public key
            const metadata = {
                public_key: user.pubkey,
                nip05: ndkUser.profile?.nip05 || '',
                image: ndkUser.profile?.image || ''
            };

            console.log('Sending metadata to WordPress:', metadata);

            $feedback.html('Updating profile...');

            // Send to WordPress
            const response = await $.ajax({
                url: nostr_login_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'nostr_sync_profile',
                    metadata: JSON.stringify(metadata),
                    nonce: nostr_login_ajax.nonce
                }
            });

            if (response.success) {
                $feedback.removeClass('notice-info').addClass('notice-success')
                    .html('Successfully synced Nostr data!');
                
                // Update displayed values
                $('#nostr-public-key').val(metadata.public_key);
                $('#nostr-nip05').val(metadata.nip05);
                
                // Refresh page if avatar updated
                if (metadata.image) {
                    setTimeout(() => location.reload(), 1500);
                }
            } else {
                throw new Error(response.data.message || 'Failed to update profile');
            }
        } catch (error) {
            console.error('Sync error:', error);
            $feedback.removeClass('notice-info').addClass('notice-error')
                .html(`Error: ${error.message}`);
        } finally {
            $button.prop('disabled', false);
        }
    }

    // Add event listener
    $(document).ready(function() {
        console.log('Nostr login script loaded'); // Debug log
        
        const $connectButton = $('#nostr-connect-extension');
        const $resyncButton = $('#nostr-resync-extension');
        
        if ($connectButton.length || $resyncButton.length) {
            console.log('Found Nostr connect/resync buttons'); // Debug log
        }
        
        $('#nostr-connect-extension, #nostr-resync-extension').on('click', function(e) {
            console.log('Nostr sync button clicked'); // Debug log
            handleNostrSync(e);
        });
    });
  });
})(jQuery);
