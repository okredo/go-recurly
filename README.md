go-recurly
==========

Gigaom Recurly integration

This plugin manages user's Recurly subscription and data synchronization.

Dependency: [go-subscriptions](https://github.com/GigaOM/go-subscriptions.git)
Dependency: [bStat](https://github.com/misterbisson/bstat)

Hacking notes
=============
We are also tracking using bStat:
* every new trial or paid subscription reported by go-subscriptions
* subscription cancellations in go-recurly

Struggles & annoyances
-======================
* bStat tracking
  - We are still not tracking the following desired events:
    - Subscription Renewal and
    - Subscription Expiration
  - bStat requires a post ID.  In this plugin we obtain this from our config
    - However, per https://github.com/misterbisson/bstat/pull/5#discussion_r11949312, we might dispense with this in bStat
