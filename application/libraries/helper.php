<?php

class Helper {

    public static function status($status) {
        switch ($status) {
        	case 'closed':	$status = 'ok-sign text-success'; break;
        	case 'open':	$status = 'exclamation-sign text-warning'; break;
        	default:		$status	= 'question-sign text-info'; break;
        }

        return '<i class="icon-' . $status . '"></i> ';
    }
}