<?php
/**
 * Copyright 2005-2011 MERETHIS
 * Centreon is developped by : Julien Mathis and Romain Le Merlus under
 * GPL Licence 2.0.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation ; either version 2 of the License.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <http://www.gnu.org/licenses>.
 *
 * Linking this program statically or dynamically with other modules is making a
 * combined work based on this program. Thus, the terms and conditions of the GNU
 * General Public License cover the whole combination.
 *
 * As a special exception, the copyright holders of this program give MERETHIS
 * permission to link this program with independent modules to produce an executable,
 * regardless of the license terms of these independent modules, and to copy and
 * distribute the resulting executable under terms of MERETHIS choice, provided that
 * MERETHIS also meet, for each linked independent module, the terms  and conditions
 * of the license of that module. An independent module is a module which is not
 * derived from this program. If you modify this program, you may extend this
 * exception to your version of the program, but you are not obliged to do so. If you
 * do not wish to do so, delete this exception statement from your version.
 *
 * For more information : contact@centreon.com
 *
 */

require_once "../../require.php";
require_once $centreon_path . 'www/class/centreon.class.php';
require_once $centreon_path . 'www/class/centreonSession.class.php';
require_once $centreon_path . 'www/class/centreonDB.class.php';
require_once $centreon_path . 'www/class/centreonACL.class.php';
require_once $centreon_path . 'www/class/centreonHost.class.php';
require_once $centreon_path . 'www/class/centreonService.class.php';
require_once $centreon_path . 'www/class/centreonExternalCommand.class.php';
require_once $centreon_path . 'www/class/centreonUtils.class.php';

session_start();

var_dump($_POST);

try {
    if (!isset($_SESSION['centreon']) || !isset($_POST['cmdType']) || !isset($_POST['hosts']) ||
        !isset($_POST['author'])) {
        throw new Exception('Missing data');
    }
    $db = new CentreonDB();
    if (CentreonSession::checkSession(session_id(), $db) == 0) {
        throw new Exception('Invalid session');
    }
    $type = $_POST['cmdType'];
    $centreon = $_SESSION['centreon'];
    $hosts = explode(',', $_POST['hosts']);
    $oreon = $centreon;
    $externalCmd = new CentreonExternalCommand($centreon);
    $hostObj = new CentreonHost($db);
    $svcObj = new CentreonService($db);
    $command = "";
    $author = $_POST['author'];
    $comment = "";

    if (isset($_POST['comment'])) {
        $comment = $_POST['comment'];
    }
    if ($type == 'ack') {
        $persistent = 0;
        $sticky = 0;
        $notify = 0;
        if (isset($_POST['persistent'])) {
            $persistent = 1;
        }
        if (isset($_POST['sticky'])) {
            $sticky = 1;
        }
        if (isset($_POST['notify'])) {
            $notify = 1;
        }
        $command = "ACKNOWLEDGE_HOST_PROBLEM;%s;$sticky;$notify;$persistent;$author;$comment";
        $commandSvc = "ACKNOWLEDGE_SVC_PROBLEM;%s;%s;$sticky;$notify;$persistent;$author;$comment";
        if (isset($_POST['forcecheck'])) {
            $forceCmd = "SCHEDULE_FORCED_HOST_CHECK;%s;".time();
            $forceCmdSvc = "SCHEDULE_FORCED_SVC_CHECK;%s;%s;".time();
        }
    } elseif ($type == 'downtime') {
        $fixed = 0;
        if (isset($_POST['fixed'])) {
            $fixed = 1;
        }
        $duration = 0;
        if (isset($_POST['dayduration'])) {
            $duration += ($_POST['dayduration'] * 86400);
        }
        if (isset($_POST['hourduration'])) {
            $duration += ($_POST['hourduration'] * 3600);
        }
        if (isset($_POST['minuteduration'])) {
            $duration += ($_POST['minuteduration'] * 60);
        }

        if (!isset($_POST['start']) || !isset($_POST['end'])) {
            throw new Exception ('Missing downtime start/end');
        }

        if (isset($_POST['start_time']) && $_POST['start_time']) {
            $tab = explode(':', $_POST['start_time']);
            $tmpHstart = trim($tab[0]);
            $tmpMstart = trim($tab[1]);
        } else {
            $tmpHstart = "00";
            $tmpMstart = "00";
        }

        if (isset($_POST['end_time']) && $_POST['end_time']) {
            $tab = explode(':', $_POST['end_time']);
            $tmpHend = trim($tab[0]);
            $tmpMend = trim($tab[1]);
        } else {
            $tmpHend = "00";
            $tmpMend = "00";
        }

        $dateStart = $_POST['start'];
        $start = $dateStart . " " . $tmpHstart . ":" . $tmpMstart;
        $start = CentreonUtils::getDateTimeTimestamp($start);
        $dateEnd = $_POST['end'];
        $end = $dateEnd . " " . $tmpHend . ":" . $tmpMend;
        $end = CentreonUtils::getDateTimeTimestamp($end);
        $command = "SCHEDULE_HOST_DOWNTIME;%s;$start;$end;$fixed;0;$duration;$author;$comment";
        $commandSvc = "SCHEDULE_SVC_DOWNTIME;%s;%s;$start;$end;$fixed;0;$duration;$author;$comment";
    } else {
        throw new Exception('Unknown command');
    }
    if ($command != "") {
        foreach ($hosts as $hostId) {
            $hostname = $hostObj->getHostName($hostId);
            $pollerId = $hostObj->getHostPollerId($hostId);
            $externalCmd->setProcessCommand(sprintf($command, $hostname), $pollerId);
            if (isset($forceCmd)) {
                $externalCmd->setProcessCommand(sprintf($forceCmd, $hostname), $pollerId);
            }
            if (isset($_POST['processServices'])) {
                $services = $svcObj->getServiceId(null, $hostname);
                foreach($services as $svcDesc => $svcId) {
                    $externalCmd->setProcessCommand(sprintf($commandSvc, $hostname, $svcDesc), $pollerId);
                    if (isset($forceCmdSvc)) {
                        $externalCmd->setProcessCommand(sprintf($forceCmdSvc, $hostname, $svcDesc), $pollerId);
                    }
                }
            }
        }
        $externalCmd->write();
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
