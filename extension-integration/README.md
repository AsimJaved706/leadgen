# Leadspace Chrome extension integration

Run `install-extension-integration.ps1` from PowerShell to apply these integration files to the existing extractor folder beside `frontend`.

Then open `chrome://extensions`, enable Developer mode, choose **Load unpacked**, and select the `google-maps-extractor-main` extension folder. If it was already loaded, press **Reload**.

The popup opens the Leadspace login through `chrome.identity`. Successful login issues a scoped token that expires after 15 minutes. The popup and Google Maps toolbar always request current workspace access from the server before extraction or saving. Select a workspace and Lead List in the popup, extract records in Google Maps, and press **Save to Leadspace**.
