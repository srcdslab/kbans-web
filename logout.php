<?php

    include_once('functions_global.php');

    /* Nothing may be written to the response before this point. This file used
       to `echo "Please wait...."` first, which flushed the body and made every
       cookie/header call below -- and the redirect -- fail with "Cannot modify
       header information - headers already sent". Clicking Logout left the
       browser still fully authenticated, on a page that said "Please wait....". */
    destroyAdminSession();

    /* Also drop the pre-session login cookies, so a browser upgrading from the
       old shared-secret scheme stops carrying them around. */
    clearLoginCookies();

    header("Location: index.php?all");
    die();
?>
