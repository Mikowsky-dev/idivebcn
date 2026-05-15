<?php
// Disable canonical redirect to prevent infinite redirect loop on homepage
// Added 2026-05-14 during server crisis recovery
add_filter('redirect_canonical', '__return_false', 999);
