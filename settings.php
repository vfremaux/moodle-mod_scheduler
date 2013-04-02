<?php

$options = array(0 => get_string('no'), 1 => get_string('yes'));
$settings->add(new admin_setting_configselect('scheduler_allteachersgrading', get_string('allteachersgrading', 'scheduler'),
                   get_string('configallteachersgrading', 'scheduler'), 1, $options));

