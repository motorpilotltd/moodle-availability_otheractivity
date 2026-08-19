// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * JavaScript for the other activity availability condition form.
 *
 * The activity picker is fed by an AJAX search of the whole site, since the
 * full list of completion-enabled activities may be vast.
 *
 * @module     moodle-availability_otheractivity-form
 * @copyright  2026 Simon Lewis
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
M.availability_otheractivity = M.availability_otheractivity || {}; // eslint-disable-line camelcase

/**
 * @class M.availability_otheractivity.form
 * @extends M.core_availability.plugin
 */
M.availability_otheractivity.form = Y.Object(M.core_availability.plugin);

/**
 * Course module id being edited, excluded from search results.
 *
 * @property excludecmid
 * @type Number
 */
M.availability_otheractivity.form.excludecmid = 0;

/**
 * Initialises this plugin.
 *
 * @method initInner
 * @param {Number} excludecmid Course module id being edited, or 0
 */
M.availability_otheractivity.form.initInner = function(excludecmid) {
    this.excludecmid = excludecmid;
};

/**
 * Calls the search web service.
 *
 * @method callService
 * @param {Object} args Web service arguments
 * @param {Function} done Success callback, receives the result
 * @param {Function} fail Failure callback
 */
M.availability_otheractivity.form.callService = function(args, done, fail) {
    window.require(['core/ajax'], function(Ajax) {
        Ajax.call([{
            methodname: 'availability_otheractivity_search_activities',
            args: args
        }])[0].done(done).fail(fail);
    });
};

/**
 * Builds the form node for one condition.
 *
 * @method getNode
 * @param {Object} json Saved condition data
 * @return {Y.Node} The form node
 */
M.availability_otheractivity.form.getNode = function(json) {
    var html;
    var node;
    var searchField;
    var cmField;
    var stateField;
    var statusNode;
    var timer = null;
    var self = this;

    /**
     * Fetches a string for this component.
     *
     * @param {String} key The string key
     * @param {Object} [param] Optional string parameter
     * @return {String} The string
     */
    var str = function(key, param) {
        return M.util.get_string(key, 'availability_otheractivity', param);
    };

    html = '<label class="mr-1 me-1">' + str('label_search') + ' ' +
        '<span class="availability-group"><input type="text" name="search" class="form-control" ' +
        'placeholder="' + Y.Escape.html(str('searchhint')) + '"></span></label> ';
    html += '<label class="mr-1 me-1">' + str('label_activity') + ' ' +
        '<span class="availability-group"><select name="cm" class="custom-select form-select">' +
        '<option value="0">' + M.util.get_string('choosedots', 'moodle') + '</option>' +
        '</select></span></label> ';
    html += '<label>' + str('label_condition') + ' ' +
        '<span class="availability-group"><select name="e" class="custom-select form-select">' +
        '<option value="1">' + Y.Escape.html(str('option_complete')) + '</option>' +
        '<option value="0">' + Y.Escape.html(str('option_incomplete')) + '</option>' +
        '<option value="2">' + Y.Escape.html(str('option_pass')) + '</option>' +
        '<option value="3">' + Y.Escape.html(str('option_fail')) + '</option>' +
        '</select></span></label>';
    html += '<div class="availability-otheractivity-status small text-muted w-100" aria-live="polite"></div>';

    node = Y.Node.create('<span class="form-inline availability-otheractivity">' + html + '</span>');

    searchField = node.one('input[name=search]');
    cmField = node.one('select[name=cm]');
    stateField = node.one('select[name=e]');
    statusNode = node.one('.availability-otheractivity-status');

    /**
     * Runs the search for the current query and repopulates the picker.
     */
    var runSearch = function() {
        var query = searchField.get('value');

        if (query.replace(/^\s+|\s+$/g, '').length < 2) {
            statusNode.setHTML(Y.Escape.html(str('searchhint')));
            return;
        }

        statusNode.setHTML(Y.Escape.html(str('searching')));
        self.callService({query: query, excludecmid: self.excludecmid}, function(result) {
            var current = cmField.get('value');
            var currentOption = cmField.one('option[value="' + current + '"]');
            var markup = '<option value="0">' + M.util.get_string('choosedots', 'moodle') + '</option>';
            var found = false;

            Y.Array.each(result.activities, function(activity) {
                markup += '<option value="' + activity.cmid + '">' + Y.Escape.html(activity.label) + '</option>';
                if ('' + activity.cmid === current) {
                    found = true;
                }
            });

            // Keep the current selection visible even when it no longer
            // matches the search, so a saved value cannot be lost by
            // searching for something else.
            if (current !== '0' && !found && currentOption) {
                markup = '<option value="' + current + '">' +
                    Y.Escape.html(currentOption.get('text')) + '</option>' + markup;
            }

            cmField.setHTML(markup);
            cmField.set('value', current);

            if (result.activities.length === 0) {
                statusNode.setHTML(Y.Escape.html(str('noresults')));
            } else if (result.more) {
                statusNode.setHTML(Y.Escape.html(str('toomanyresults')));
            } else {
                statusNode.setHTML('');
            }
        }, function() {
            statusNode.setHTML(Y.Escape.html(str('searchfailed')));
        });
    };

    // Restore a saved value: show a placeholder label immediately, then
    // resolve the real label in the background.
    if (json.cm !== undefined && json.cm) {
        cmField.append('<option value="' + json.cm + '">' +
            Y.Escape.html(str('cmidlabel', json.cm)) + '</option>');
        cmField.set('value', '' + json.cm);
        this.callService({cmid: json.cm}, function(result) {
            var option = cmField.one('option[value="' + json.cm + '"]');
            if (option && result.activities.length) {
                option.set('text', result.activities[0].label);
            }
        }, function() {
            // Leave the placeholder label in place.
        });
    }
    if (json.e !== undefined) {
        stateField.set('value', '' + json.e);
    }

    searchField.on('valuechange', function() {
        if (timer) {
            timer.cancel();
        }
        timer = Y.later(400, null, runSearch);
    });

    cmField.on('change', function() {
        M.core_availability.form.update();
    });
    stateField.on('change', function() {
        M.core_availability.form.update();
    });

    return node;
};

/**
 * Reads values out of the form node.
 *
 * @method fillValue
 * @param {Object} value Object to fill
 * @param {Y.Node} node The form node
 */
M.availability_otheractivity.form.fillValue = function(value, node) {
    value.cm = parseInt(node.one('select[name=cm]').get('value'), 10);
    if (isNaN(value.cm)) {
        value.cm = 0;
    }
    value.e = parseInt(node.one('select[name=e]').get('value'), 10);
};

/**
 * Reports validation errors.
 *
 * @method fillErrors
 * @param {Array} errors Array of error strings
 * @param {Y.Node} node The form node
 */
M.availability_otheractivity.form.fillErrors = function(errors, node) {
    var value = {};
    this.fillValue(value, node);

    if (!value.cm) {
        errors.push('availability_otheractivity:error_selectcm');
    }
};
