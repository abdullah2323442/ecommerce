<?php

return [
    /*
     | Show medicine pack photos. The imported photos carry a MedEx watermark, so there is one switch to turn
     | them all off (no photo URL then leaves the server) if the rights question is ever settled the wrong way.
     */
    'medicine_photos' => (bool) env('CATALOG_MEDICINE_PHOTOS', true),
];
