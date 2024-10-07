import NDK from "@nostr-dev-kit/ndk";
import { NDKNip07Signer } from "@nostr-dev-kit/ndk";
import { getPublicKey } from "nostr-tools/pure";
import { nip19 } from "nostr-tools"; 

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
        "wss://nostr.wine",
        "wss://relay.snort.social",
        "wss://eden.nostr.land",
        "wss://nostr.bitcoiner.social",
        "wss://nostrpub.yeghro.site",

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
        // console.log("user pubkey:", publicKey);

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
          // console.log("connected to relays", ndk);
        } catch (error) {
          // console.error("Failed to connect to relays:", error);
          alert("Failed to connect to Nostr relays. Please try again later.");
          return;
        }

        // Create user object with the public key and signer if available
        let user = ndk.getUser({ pubkey: publicKey });
        if (signer) {
          user.signer = signer;
        }
        // console.log("set user:", user);

        // Fetch user profile with timeout
        const fetchProfilePromise = user.fetchProfile();
        const fetchProfileTimeout = new Promise((_, reject) =>
          setTimeout(
            () => reject(new Error("Fetching user profile timed out")),
            TIMEOUT_DURATION
          )
        );

        try {
          await Promise.race([fetchProfilePromise, fetchProfileTimeout]);
        } catch (error) {
          // console.error("Failed to fetch user profile:", error);
          alert(
            "Failed to fetch user profile from Nostr. Proceeding with login using available information."
          );
        }

        // Get user metadata
        const metadata = user.profile || {};
        // console.log("stored user metadata:", metadata);

        // Send login request to WordPress
        $.ajax({
          url: nostr_login_ajax.ajax_url,
          type: "POST",
          data: {
            action: "nostr_login",
            public_key: publicKey,
            metadata: JSON.stringify(metadata),
            nonce: nostr_login_ajax.nonce,
          },
          success: function (response) {
            if (response.success) {
              window.location.href = response.data.redirect;
            } else {
              alert(response.data.message);
            }
          },
          error: function () {
            alert("An error occurred. Please try again.");
          },
        });
      } catch (error) {
        // console.error("Nostr login error:", error);
        throw error;
      }
    }
  });
})(jQuery);
