<?php

# Check we have been included and not called directly
require( dirname(__FILE__) . '/../../../functions/include-only.php' );

# check if site is demo
$User->is_demo();

# Don't corrupt output with php errors!
disable_php_errors();

/*
 * Update switch port of all hosts in subnet
 *******************************/

# scan disabled
if ($User->settings->enableSNMP!="1")           { $Result->show("danger", "SNMP module disabled", true); }
# subnet check
$subnet = $Subnets->fetch_subnet ("id", $POST->subnetId);
if ($subnet===false)                            { $Result->show("danger", "Invalid subnet Id", true);  }

# verify that user has write permissionss for subnet
if($Subnets->check_permission ($User->user, $POST->subnetId) != 3) 	{ $Result->show("danger", _('You do not have permissions to modify hosts in this subnet')."!", true, true); }

# fetch vlan
$vlan = $Tools->fetch_object ("vlans", "vlanId", $subnet->vlanId);
if ($vlan===false)                              { $Result->show("danger", _("Subnet must have VLAN assigned for Switch-Port query"), true);  }

# set class
$Snmp = new phpipamSNMP ();
$Database 	= new Database_PDO;
$Log = new Logging($Database);

// fetch all hosts to be scanned
$all_subnet_hosts = (array) $Addresses->fetch_subnet_addresses ($POST->subnetId);

// execute only if some exist
if (sizeof($all_subnet_hosts)>0) {
    // set default statuses
    foreach ($all_subnet_hosts as $h) {
        $result[$h->ip_addr] = (array) $h;
        $result[$h->ip_addr]['code'] = 1;
        $result[$h->ip_addr]['status'] = "Offline";
    }

    // if deviceGroup is defined -> use deviceGroup
    // else use traditional method of device selection
    if ($subnet->deviceGroup != 0) {
        $deviceGroup_members = $Tools->fetch_multiple_objects("device_to_group", "g_id", $subnet->deviceGroup, "d_id");
        $devices_used = false;

        if ($deviceGroup_members !== false && sizeof($deviceGroup_members) > 0) {
            $devices_used = [];

            foreach ($deviceGroup_members as $deviceGroup_member) {
                $device = $Tools->fetch_object("devices", "id", $deviceGroup_member->d_id);

                // Only add devices with corresponding SNMP-query
                if (str_contains($device->snmp_queries, "get_interface_name")) {
                    $devices_used[] = $device;
                }
            }
        }
    } else {
        # fetch devices that use get_interface_name query
        $devices_used = $Tools->fetch_multiple_objects ("devices", "snmp_queries", "%get_interface_name%", "id", true, true);
    }

    # filter out not in this section
    if ($devices_used !== false) {
        foreach ($devices_used as $d) {
            // get possible sections
            $permitted_sections = pf_explode(";", $d->sections);
            // check
            if (in_array($subnet->sectionId, $permitted_sections)) {
                $permitted_devices[] = $d;
            }
        }
    }

    // if none set die
    if (!isset($permitted_devices))                 { $Result->show("danger", "No devices for SNMP Switch-Port query available", true); }

    // ok, we have devices, connect to each device and do query
    foreach ($permitted_devices as $d) {
        // init
        $Snmp->set_snmp_device ($d, $vlan->number);
        // execute
        try {
           $res = $Snmp->get_query("get_interface_name");
           // remove those not in subnet
           if (is_array($res) && sizeof($res)>0) {
               // save for debug
               $debug[$d->hostname]["get_interface_name"] = $res;
               // check
               foreach ($res as $kr=>$r) {
                    if ($addr['mac'] != null && $r['mac'] != null && strtolower($addr['mac']) === strtolower($r['mac'])) {
                        // add to alive
                        $result[$Subnets->transform_address($r['ip'], "decimal")]['code'] = 0;
                        $result[$Subnets->transform_address($r['ip'], "decimal")]['status'] = "Online";
                        $result[$Subnets->transform_address($r['ip'], "decimal")]['switch'] = $d->hostname;
                        $result[$Subnets->transform_address($r['ip'], "decimal")]['port'] = $r['port'];

                        // update alive time and mac address and port
                        if (@$Scan->update_address_port($addr['id'], null, $d->id, $r['port'])) {
                            $Log->write_changelog("ip_addr", "edit", "success", $addr, $result[$Subnets->transform_address($r['ip'], "decimal")], false);
                        }
                    }
               }
           }
           $found[$d->id] = $res;

         } catch (Exception $e) {
    		$Result->show("danger", "<pre>"._("Error").": ".$e."</pre>", false); ;
    		die();
    	}
    }

    foreach ($result as $addr) {
        if ($addr['code'] === 1) {
            $result[$Subnets->transform_address($r['ip'], "decimal")]['switch'] = null;
            $result[$Subnets->transform_address($r['ip'], "decimal")]['port'] = null;
            
            if (@$Scan->update_address_port_offline($addr['id'])) {
                $Log->write_changelog("ip_addr", "edit", "success", $addr, $result[$Subnets->transform_address($r['ip'], "decimal")], false);
            }
        }
    }
}
?>




<h5><?php print _('Scan results');?>:</h5>
<hr>

<?php
# empty
if(sizeof($all_subnet_hosts)==0) 			{ $Result->show("info", _("Subnet is empty")."!", false); }
# ok
else {
	//table
	print "<table class='table table-condensed table-top'>";

	//headers
	print "<tr>";
	print "	<th>"._('IP')."</th>";
	print "	<th>"._('Description')."</th>";
	print "	<th>"._('status')."</th>";
	print "	<th>"._('Switch')."</th>";
	print "	<th>"._('Port')."</th>";
	print "</tr>";

	//loop
	foreach($result as $r) {
		//set class
		if($r['code']==0)		{ $class='success'; }
		elseif($r['code']==100)	{ $class='warning'; }
		else					{ $class='danger'; }

		print "<tr class='$class'>";
		print "	<td>".$Subnets->transform_to_dotted($r['ip_addr'])."</td>";
		print "	<td>".$r['description']."</td>";
		print "	<td>".$r['status']."</td>";
		print "	<td>".$r['switch']."</td>";
		print "	<td>".$r['port']."</td>";

		print "</tr>";
	}
	print "</table>";
}
//print scan method
print "<div class='text-right' style='margin-top:7px;'>";
print " <span class='muted'>";
print " Scan method: SNMP Switch-Port<hr>";
print " Scanned devices: <br>";
foreach ($debug as $k=>$d) {
    print "&middot; ".$k."<br>";
}
print "</span>";
print "</div>";

# show debug?
if($POST->debug==1) 				{ print "<pre>"; print_r($debug); print "</pre>"; }
