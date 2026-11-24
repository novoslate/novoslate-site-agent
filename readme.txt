=== Novoslate Site Agent ===
Contributors: novoslate
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later

Reports WordPress health (pending updates, versions) to Novoslate Ops once a day, and sends form leads when Capture form leads is on.

== Description ==
Runs alongside ManageWP. Once a day it sends Novoslate Ops the pending core, plugin and theme updates, plus the WordPress and PHP versions. It never changes settings, updates or content on the site.

Form lead capture is off until "Capture form leads" is turned on for the client in Ops (read with the daily report). Then each successful, non-spam submission from Elementor Pro, Divi, Contact Form 7 or Gravity Forms is sent to Ops in the background: form name and id, page, first name, service or subject, SHA-256 hashes of the email and phone, and ad attribution (gclid, gbraid, wbraid, UTMs, landing page, referrer) from a first-party cookie kept for 90 days. Messages, addresses and other details are never sent.

== Setup ==
1. Upload the zip under Plugins > Add New > Upload Plugin and activate.
2. In Novoslate Ops, open the client and copy the Site Agent token.
3. Go to Settings > Novoslate Agent, paste the token and click Save and send report.

== Updates ==
Updates arrive from GitHub releases like any other plugin update.

== Changelog ==

= 1.2.2 =
* Test leads: visit any page with ?nsa_test=1 (a session cookie, ?nsa_test=0 clears it) and form leads from that visit are sent marked test, so Ops leaves them out of counts.

= 1.2.1 =
* Elementor Pro: a form submission is captured even when the form's own email or another action fails (validation and spam checks still apply).

= 1.2.0 =
* Form lead capture for Elementor Pro, Divi, Contact Form 7 and Gravity Forms, switched on per client from Novoslate Ops.
* Daily report includes the active form plugins and the lead queue.

= 1.1.1 =
* Updates now come from the novoslate GitHub organization.

= 1.1.0 =
* Updates itself from GitHub releases.
* Endpoint defaults to ops.novoslate.com, so only the token is needed.

= 1.0.0 =
* First release.
