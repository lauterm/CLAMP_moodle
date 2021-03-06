<?php
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
 * @package   block_clampmail
 * @copyright 2013 Collaborative Liberal Arts Moodle Project
 * @copyright 2012 Louisiana State University (original Quickmail block)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir . '/formslib.php');

class email_form extends moodleform {
    private function reduce_users($in, $user) {
        return $in . '<option value="'.$this->option_value($user).'">'.
               $this->option_display($user).'</option>';
    }

    private function option_display($user) {
        $users_to_groups = $this->_customdata['users_to_groups'];

        if (empty($users_to_groups[$user->id])) {
            $groups = clampmail::_s('no_section');
        } else {
            $only_names = function($group) { return $group->name; };
            $groups = implode(',', array_map($only_names, $users_to_groups[$user->id]));
        }

        return sprintf("%s (%s)", fullname($user), $groups);
    }

    private function option_value($user) {
        $users_to_groups = $this->_customdata['users_to_groups'];
        $users_to_roles = $this->_customdata['users_to_roles'];

        $only_sn = function($role) { return $role->shortname; };

        $roles = implode(',', array_map($only_sn, $users_to_roles[$user->id]));

        // Everyone defaults to none.
        $roles .= ',none';

        if (empty($users_to_groups[$user->id])) {
            $groups = 0;
        } else {
            $only_id = function($group) { return $group->id; };
            $groups = implode(',', array_map($only_id, $users_to_groups[$user->id]));
            $groups .= ',all';
        }

        return sprintf("%s %s %s", $user->id, $groups, $roles);
    }

    public function definition() {
        global $CFG, $USER, $COURSE, $OUTPUT;

        $mform =& $this->_form;

        $mform->addElement('hidden', 'mailto', '');
        $mform->setType('mailto', PARAM_RAW);
        $mform->addElement('hidden', 'userid', $USER->id);
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'courseid', $COURSE->id);
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'type', '');
        $mform->setType('type', PARAM_ALPHA);
        $mform->addElement('hidden', 'typeid', 0);
        $mform->setType('typeid', PARAM_INT);

        $role_options = array('none' => clampmail::_s('no_filter'));
        foreach ($this->_customdata['roles'] as $role) {
            $role_options[$role->shortname] = $role->name;
        }

        $group_options = empty($this->_customdata['groups']) ? array() : array(
            'all' => clampmail::_s('all_sections')
        );
        foreach ($this->_customdata['groups'] as $group) {
            $group_options[$group->id] = $group->name;
        }
        $group_options[0] = clampmail::_s('no_section');

        $user_options = array();
        foreach ($this->_customdata['users'] as $user) {
            $user_options[$this->option_value($user)] = $this->option_display($user);
        }

        $links = array();
        $gen_url = function($type) use ($COURSE) {
            $email_param = array('courseid' => $COURSE->id, 'type' => $type);
            return new moodle_url('emaillog.php', $email_param);
        };

        $draft_link = html_writer::link ($gen_url('drafts'), clampmail::_s('drafts'));
        $links[] =& $mform->createElement('static', 'draft_link', '', $draft_link);

        $context = context_course::instance($COURSE->id);

        $config = clampmail::load_config($COURSE->id);

        $can_send = (
            has_capability('block/clampmail:cansend', $context) or
            !empty($config['allowstudents'])
        );

        if ($can_send) {
            $history_link = html_writer::link($gen_url('log'), clampmail::_s('history'));
            $links[] =& $mform->createElement('static', 'history_link', '', $history_link);
        }

        $mform->addGroup($links, 'links', '&nbsp;', array(' | '), false);

        $req_img = html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('req'), 'class' => 'req'));

        $table = new html_table();
        $table->attributes['class'] = 'emailtable';

        $selected_required_label = new html_table_cell();
        $selected_required_label->text = html_writer::tag('strong',
            clampmail::_s('selected') . $req_img, array('class' => 'required'));

        $role_filter_label = new html_table_cell();
        $role_filter_label->colspan = "2";
        $role_filter_label->text = html_writer::tag('div',
            clampmail::_s('role_filter'), array('class' => 'object_labels'));

        $select_filter = new html_table_cell();
        $select_filter->text = html_writer::tag('select',
            array_reduce($this->_customdata['selected'], array($this, 'reduce_users'), ''),
            array('id' => 'mail_users', 'multiple' => 'multiple', 'size' => 30));

        $embed = function ($text, $id) {
            return html_writer::tag('p',
                html_writer::empty_tag('input', array(
                    'value' => $text, 'type' => 'button', 'id' => $id
                ))
            );
        };

        $embed_quick = function ($text) use ($embed) {
            return $embed(clampmail::_s($text), $text);
        };

        $center_buttons = new html_table_cell();
        $center_buttons->text = (
            $embed($OUTPUT->larrow() . ' ' . clampmail::_s('add_button'), 'add_button') .
            $embed(clampmail::_s('remove_button') . ' ' . $OUTPUT->rarrow(), 'remove_button') .
            $embed_quick('add_all') .
            $embed_quick('remove_all')
        );

        $filters = new html_table_cell();
        $filters->text = html_writer::tag('div',
            html_writer::select($role_options, '', 'none', null, array('id' => 'roles'))
        ) . html_writer::tag('div',
            clampmail::_s('potential_sections'),
            array('class' => 'object_labels')
        ) . html_writer::tag('div',
            html_writer::select($group_options, '', 'all', null,
            array('id' => 'groups', 'multiple' => 'multiple', 'size' => 5))
        ) . html_writer::tag('div',
            clampmail::_s('potential_users'),
            array('class' => 'object_labels')
        ) . html_writer::tag('div',
            html_writer::select($user_options, '', '', null,
            array('id' => 'from_users', 'multiple' => 'multiple', 'size' => 20))
        );

        $table->data[] = new html_table_row(array($selected_required_label, $role_filter_label));
        $table->data[] = new html_table_row(array($select_filter, $center_buttons, $filters));

        if (has_capability('block/clampmail:allowalternate', $context)) {
            $alternates = $this->_customdata['alternates'];
        } else {
            $alternates = array();
        }

        if (empty($alternates)) {
            $mform->addElement('static', 'from', clampmail::_s('from'), $USER->email);
            $mform->setType('from', PARAM_EMAIL);
        } else {
            $options = array(0 => $USER->email) + $alternates;
            $mform->addElement('select', 'alternateid', clampmail::_s('from'), $options);
            $mform->setType('alternateid', PARAM_INT);
        }

        $mform->addElement('static', 'selectors', '', html_writer::table($table));
        $mform->setType('selectors', PARAM_RAW);

        $mform->addElement('filemanager', 'attachments', clampmail::_s('attachment'));
        $mform->setType('attachments', PARAM_FILE);

        $mform->addElement('text', 'subject', clampmail::_s('subject'));
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('editor', 'message_editor', clampmail::_s('message'),
            null, $this->_customdata['editor_options']);

        $options = $this->_customdata['sigs'] + array(-1 => 'No '. clampmail::_s('sig'));
        $mform->addElement('select', 'sigid', clampmail::_s('signature'), $options);

        $radio = array(
            $mform->createElement('radio', 'receipt', '', get_string('yes'), 1),
            $mform->createElement('radio', 'receipt', '', get_string('no'), 0)
        );

        $mform->addGroup($radio, 'receipt_action', clampmail::_s('receipt'), array(' '), false);
        $mform->addHelpButton('receipt_action', 'receipt', 'block_clampmail');
        $mform->setDefault('receipt', !empty($config['receipt']));

        $buttons = array();
        $buttons[] =& $mform->createElement('submit', 'send', clampmail::_s('send_email'));
        $buttons[] =& $mform->createElement('submit', 'draft', clampmail::_s('save_draft'));
        $buttons[] =& $mform->createElement('cancel');

        $mform->addGroup($buttons, 'buttons', clampmail::_s('actions'), array(' '), false);
    }
}
