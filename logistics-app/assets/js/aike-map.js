// Shared Leaflet map factory — the single place every map on the site is configured, so zoom
// limits and interaction behaviour can never drift between pages again.
//
// - maxZoom 19 (satisfies "zoom in to level 18" with headroom; OSM serves tiles to z19 so the
//   higher cap costs nothing).
// - zoomSnap/zoomDelta 0.5 for smooth fractional zooming; tuned wheel step.
// - tap:false works around the long-standing Leaflet iOS/Android double-tap bug so panning and
//   pinch-zoom stay smooth on mobile.
//
// Usage:
//   const map = AikeMap.create('map_el', { center: [lat, lng], zoom: 14 });
//   AikeMap.tile(map, { opacity: 0.6 });   // add an extra overlay tile layer if needed
(function (global) {
    'use strict';

    var AikeMap = {
        TILE_URL: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        MAX_ZOOM: 19,

        // Add a tile layer to a map (the base OSM layer by default). Extra overlays can pass their
        // own url as opts.url and any Leaflet tileLayer options.
        tile: function (map, opts) {
            opts = opts || {};
            var url = opts.url || AikeMap.TILE_URL;
            var options = Object.assign(
                { maxZoom: AikeMap.MAX_ZOOM, attribution: '&copy; OpenStreetMap contributors' },
                opts
            );
            delete options.url;
            return L.tileLayer(url, options).addTo(map);
        },

        // Create a fully-configured map. opts: { center:[lat,lng], zoom:Number, tile:false to skip
        // the base layer, mapOptions:{...} to override any Leaflet map option }.
        create: function (el, opts) {
            opts = opts || {};
            var mapOptions = Object.assign(
                {
                    zoomControl: true,
                    maxZoom: AikeMap.MAX_ZOOM,
                    zoomSnap: 0.5,
                    zoomDelta: 0.5,
                    wheelPxPerZoomLevel: 100,
                    tap: false
                },
                opts.mapOptions || {}
            );
            var map = L.map(el, mapOptions);
            if (opts.center) {
                map.setView(opts.center, opts.zoom != null ? opts.zoom : 14);
            }
            if (opts.tile !== false) {
                AikeMap.tile(map);
            }
            return map;
        }
    };

    global.AikeMap = AikeMap;
})(window);
