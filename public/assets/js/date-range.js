/*
 * Aulia shared date picker helpers.
 *
 * Single source of truth for bootstrap-daterangepicker locale + presets; the
 * canonical data is emitted by app/Views/layout/main.php as
 * window.AULIA_DATEPICKER (from App\Config\DatePicker). Views must not
 * re-declare locale or preset ranges; they call AuliaDateRange.locale() and
 * AuliaDateRange.ranges() instead.
 *
 * Loaded globally by the layout, after moment + daterangepicker.
 */
(function (window) {
    'use strict';

    var CFG = window.AULIA_DATEPICKER || {};
    var LOCALE = CFG.locale || {};
    var PRESETS = CFG.presets || [];
    var DISPLAY_FORMAT = LOCALE.format || 'DD/MM/YYYY';
    var SEPARATOR = LOCALE.separator || ' - ';

    function momentOrNull() {
        return window.moment || null;
    }

    /** Build the canonical { label: [moment, moment] } preset map. */
    function buildRanges() {
        var m = momentOrNull();
        if (!m) {
            return {};
        }

        var ranges = {};

        PRESETS.forEach(function (preset) {
            switch (preset.key) {
                case 'today':
                    ranges[preset.label] = [m(), m()];
                    break;
                case 'yesterday':
                    ranges[preset.label] = [
                        m().subtract(1, 'days'),
                        m().subtract(1, 'days'),
                    ];
                    break;
                case 'last7':
                    ranges[preset.label] = [m().subtract(6, 'days'), m()];
                    break;
                case 'last30':
                    ranges[preset.label] = [m().subtract(29, 'days'), m()];
                    break;
                case 'thisMonth':
                    ranges[preset.label] = [
                        m().startOf('month'),
                        m().endOf('month'),
                    ];
                    break;
                case 'lastMonth':
                    ranges[preset.label] = [
                        m().subtract(1, 'month').startOf('month'),
                        m().subtract(1, 'month').endOf('month'),
                    ];
                    break;
                default:
                    break;
            }
        });

        return ranges;
    }

    function formatRange(m) {
        return m.startDate.format(DISPLAY_FORMAT) +
            SEPARATOR +
            m.endDate.format(DISPLAY_FORMAT);
    }

    var AuliaDateRange = {
        /** Canonical daterangepicker locale object. */
        locale: function () {
            return LOCALE;
        },

        /** Canonical preset ranges object. */
        ranges: function () {
            return buildRanges();
        },

        /**
         * Programmatically set a range picker and its hidden inputs.
         *
         * @param {string} pickerSelector   selector of the visible picker input
         * @param {string} start            YYYY-MM-DD
         * @param {string} end              YYYY-MM-DD
         * @param {string} [hiddenStartSel] selector of the hidden start input
         * @param {string} [hiddenEndSel]   selector of the hidden end input
         */
        setRange: function (pickerSelector, start, end, hiddenStartSel, hiddenEndSel) {
            var $ = window.jQuery;
            var m = momentOrNull();
            if (!$ || !m) {
                return;
            }

            if (hiddenStartSel) {
                $(hiddenStartSel).val(start);
            }
            if (hiddenEndSel) {
                $(hiddenEndSel).val(end);
            }

            var $picker = $(pickerSelector);
            if (!$picker.length) {
                return;
            }

            var drp = $picker.data('daterangepicker');
            if (drp) {
                drp.setStartDate(m(start));
                drp.setEndDate(m(end));
                $picker.val(formatRange(drp));
            } else {
                $picker.val(m(start).format(DISPLAY_FORMAT) + SEPARATOR + m(end).format(DISPLAY_FORMAT));
            }
        },

        /**
         * Programmatically set a single-date picker and its hidden input.
         *
         * @param {string} pickerSelector
         * @param {string} value          YYYY-MM-DD
         * @param {string} [hiddenSel]
         */
        setSingle: function (pickerSelector, value, hiddenSel) {
            var $ = window.jQuery;
            var m = momentOrNull();
            if (!$ || !m) {
                return;
            }

            if (hiddenSel) {
                $(hiddenSel).val(value);
            }

            var $picker = $(pickerSelector);
            if (!$picker.length) {
                return;
            }

            var drp = $picker.data('daterangepicker');
            if (drp) {
                drp.setStartDate(m(value));
                $picker.val(m(value).format(DISPLAY_FORMAT));
            } else {
                $picker.val(m(value).format(DISPLAY_FORMAT));
            }
        },
    };

    /** Shared Bulan + Tahun dropdown helper. */
    var AuliaMonthPicker = {
        /**
         * Read the selected `YYYY-MM`.
         *
         * @param {string} prefix element id prefix, e.g. "bulanPicker"
         * @returns {string} e.g. "2026-10"
         */
        get: function (prefix) {
            var bulanEl = document.getElementById(prefix + 'Bulan');
            var tahunEl = document.getElementById(prefix + 'Tahun');
            if (!bulanEl || !tahunEl) {
                return '';
            }

            var bulan = String(bulanEl.value).padStart(2, '0');
            return String(tahunEl.value) + '-' + bulan;
        },

        /**
         * Set the dropdowns from a `YYYY-MM` value.
         *
         * @param {string} prefix
         * @param {string} ym
         */
        set: function (prefix, ym) {
            if (!ym || ym.indexOf('-') === -1) {
                return;
            }

            var parts = ym.split('-');
            var tahunEl = document.getElementById(prefix + 'Tahun');
            var bulanEl = document.getElementById(prefix + 'Bulan');

            if (tahunEl) {
                var tahun = String(parseInt(parts[0], 10));
                var ada = Array.prototype.some.call(
                    tahunEl.options,
                    function (opt) {
                        return opt.value === tahun;
                    }
                );
                if (!ada) {
                    var optBaru = document.createElement('option');
                    optBaru.value = tahun;
                    optBaru.textContent = tahun;
                    tahunEl.appendChild(optBaru);
                }
                tahunEl.value = tahun;
            }
            if (bulanEl) {
                bulanEl.value = String(parseInt(parts[1], 10));
            }
        },
    };

    window.AuliaDateRange = AuliaDateRange;
    window.AuliaMonthPicker = AuliaMonthPicker;
})(window);
