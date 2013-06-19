<!DOCTYPE html> 
<html xmlns="http://www.w3.org/1999/xhtml">  
    <head> 
        <title>Copies History : University of Sussex</title> 
        <link rel="stylesheet" href="http://www.sussex.ac.uk/assets/css/sussex.css" type="text/css" media="screen" />
        <link rel="stylesheet" href="http://www.sussex.ac.uk/assets/css/elements.css" type="text/css" media="screen" />
        <link rel="stylesheet" href="http://www.sussex.ac.uk/assets/css/internal.css" type="text/css" media="screen" />
</head>
<body class="internal">
                <div id="page">
	<div id="pageContent" class="notMobile">

<?php
// This script connects to a Capita Alto server running sybase and provides html usage history for a given work
// Chris Keene, University of Sussex Library 2013.
//
// Installing sybase drivers
// =========================
// These are my rough notes on getting Sybase drivers and sybase php connection working
// apt-get install php5-sybase
// apt-get install freetds-dev
// [not sure if this is needed] apt-get install freetds-bin
// you can test connection with 
// tsql -H myhost.mydomain.co.uk -p 1234 -U myusername -P mypassword -D mydatabase
// edit /etc/freetds/freetds.conf so it contains 
// [connectionname]
// host = myhost.mydomain.co.uk
// port = 1234
//
// in the same dir as this script should be a file called connection.php which contains the following:
// $link = sybase_connect('connectionname', 'myusername', 'mypassword');



$bibid = $_GET["bibid"];
//$bibid = "1016685'";
//$bibid = "543015";
// mainly to stop little  bobby tables http://xkcd.com/327/
if (!is_numeric($bibid)) {
  if ($bibid !="") { //if not empty explain the number looks wrong
    echo "not a valid bibid $bibid - doesn't look like a number.";
  }
}
else { // if we *do* have a good bibid, do the rest of the page!

// see above for the contents of this file
include_once 'connection.php';
 
if (!$link) {
   die('Unable to connect!');
}
if (!sybase_select_db('prod_talis', $link)) {
   die('Unable to select database!');
}
$result = sybase_query("SELECT * FROM WORKS WHERE WORK_ID = $bibid ");
while ($row = sybase_fetch_assoc($result)) {
   //var_dump($row);
   $work_array=$row;
}
 
sybase_free_result($result);
echo "<p><a href=\"http://capitadiscovery.co.uk/sussex-ac/items/$bibid\">To Catalogue Record.</a></p>\n";
echo "<h1>Details for " . $work_array["TITLE_DISPLAY"] . "</h1>\n";





$items_array = array();
$itemids;
$sql = "SELECT * FROM ITEM i, CLASSIFICATION c 
  where WORK_ID = $bibid
  AND i.CLASS_ID = c.CLASS_ID";
$result = sybase_query($sql);
while ($row = sybase_fetch_array($result)) {
   //var_dump($row);
   $items_array[$row["BARCODE"]] = $row;
   $itemids .= $row["ITEM_ID"] . ", ";
}
sybase_free_result($result);
// remove the last comma and white space.
$itemids = rtrim($itemids, ', ');


//////////////////////////////////////////////////////////////////////////
// Copies History
// periodid 0 = Current, periodid 43 2008, and onwards
$sql = "SELECT * FROM ITEM_PERIOD_STATS i, STATS_PERIOD s, ITEM j 
 where i.ITEM_ID IN ($itemids) 
and  i.ITEM_ID = j.ITEM_ID
and i.PERIOD_ID = s.PERIOD_ID
and (i.PERIOD_ID > 43 OR i.PERIOD_ID = 0)
ORDER BY i.PERIOD_ID DESC";
$result = sybase_query($sql);
while ($row = sybase_fetch_array($result)) {
   //var_dump($row);
   // hash of hashes to hold loans for each period for each item
   $copieshistory[$row["BARCODE"]][$row["NAME"]] = $row["LOANS"];
   // hash that just keeps track of periods
   $copiesperiods[$row["PERIOD_ID"]] = $row["NAME"];
}
sybase_free_result($result);

////////////////////////////////////////
//// Output Copies History
echo "<h2>Copies History</h2>";
echo "<table class='style1'><tr><th>barcode</th>";
foreach ($copiesperiods as $periodid => $periodname) {
    echo "<th>$periodname </th>";
} 
echo "</tr>";
foreach ($copieshistory as $barcode => $namehash) {
    echo "<tr><td>$barcode</td>";
    foreach ($copiesperiods as $periodid => $periodname) {
        echo "<td>" . $namehash["$periodname"] . " </td>";
    }
}
echo "</table>";


///////////////////////////////////////////////////////////
// Items List
echo "<h2>Items List</h2>\n";
echo "<table class='style1'><tr><th>barcode</th><th>Sequence</th>
  <th>Location</th><th>Ordered date</th><th>Format</th><th>Status</th><th>Shelfmark</th></tr>";
foreach ($items_array as $barcode => $itemhash) {
    if ($itemhash["STATUS_ID"] != 5) {
	$stylecolor = 'style="color:grey"';
    }
    echo "<tr>\n";
    echo "<td  $stylecolor>$barcode</td>";
    echo "<td $stylecolor>" . $itemhash["SEQUENCE_ID"] . "\n";
    echo "<td $stylecolor>" . $itemhash["ACTIVE_SITE_ID"] . "\n";
    echo "<td $stylecolor>" . $itemhash["CREATE_DATE"] . "\n";
    echo "<td $stylecolor>" . $itemhash["FORMAT_ID"] . "\n";
    echo "<td $stylecolor>" . $itemhash["STATUS_ID"] . "\n";
    echo "<td $stylecolor>" . $itemhash["CLASS_NUMBER"] . "\n";
    echo "</tr>";
    $stylecolor = "";
}
echo "</table>\n";

/////////////////////////////////////////////////////////////////////////
// Loans
echo "<h2>Loans</h2>\n";
echo "<p>This lists all the transations for the items above, starting with the most recent and working backwards.
Transactions may be an issue, renew or return.</p>";
echo "<p> item id and borrower id are  displayed to show if loans are from the same borrower or using the same item</p>";
echo "<table class='style1'>\n";
echo "<tr><th>item id</th><th>borrower id</th><th>transaction type</th><th>date</th></tr>\n";

$sql = "SELECT * FROM LOAN WHERE ITEM_ID IN ($itemids) ORDER BY CREATE_DATE DESC
";

$result = sybase_query($sql);
// generate a short random number
$randomtwodigits = rand(10,99);
while ($row = sybase_fetch_assoc($result)) {
   if ($row["BORROWER_ID"] == "-1") {
       continue;
   }
   //var_dump($row);
   // make a consistent number for borrower which has no meaning than used here
   // mainly for privacy reasons. this uses the crypt function, but only uses the 
   // last few digits to keep it readable. 
   // Plus, each time a two digit random salt is used... so even if the end user
   // looks at two different items, they can't connect the two borrower ids together
   // which might help them to identify the user
   $bornum = substr( crypt($row["BORROWER_ID"], $randomtwodigits) ,-5);
   echo "<tr>\n";
   echo " <!-- <td>" . $row["LOAN_ID"] . " </td>\n -->";
   echo " <td>" . $row["ITEM_ID"] . " </td>\n";
   echo " <td>" . $bornum . "  </td>\n";
   if ($row["STATE"] == "0") {
       echo " <td style='font-weight:bold;color:red;'>Issue</td>\n";
   }
   elseif ($row["STATE"] == "1") {
        echo " <td>Renew</td>\n";
   }
   elseif ($row["STATE"] == "2") {
        echo "<td>return</td>";
   }
   else {
        echo "<td>.</td>";
   }
   echo " <td>" . $row["CREATE_DATE"] . " </td>\n";
   echo "</tr>\n";

}
echo "</table>";


$sql = "SELECT * FROM ITEM_MAIN_STATS WHERE ITEM_ID IN ($itemids)";
$result = sybase_query($sql);
while ($row = sybase_fetch_assoc($result)) {
  echo "<pre>";
   //var_dump($row);
  echo "</pre>";
}

//var_dump($items_array);
//var_dump($copieshistory);


} // end of code for a valid bibid.


?>
<hr />
<p>Enter a bibid in the field below:</p>

<form id="form1" name="form1" method="get" action="">
  <label for="bibid">bibid</label>
  <input name="bibid" type="text" id="bibid" size="20" maxlength="20" />
  <input type="submit" name="submit" id="submit" value="Submit" />
</form>
<hr />
<h2>boomarklet</h2>
<p>
Firefox/Chrome: Drag the following link to your bookmark bar.</p>
<p>Internet Explorer: Right click on the link below, select 'add to Favorites'. Go to your favorites menu and right click on the Copies History favorite, select 'add to favorites bar'.</p>  <p>
<a id="codeOut" href="javascript:var%20d=document,w=window,itemRE=/items\/(.*)\?/,f='http://bibinfo.lib.sussex.ac.uk/bibinfo/',l=d.location,bibid=itemRE.exec(l.href),p2='?bibid='+bibid[1],u=f+p2;try%7Bthrow('ozhismygod');%7Dcatch(z)%7Ba=function()%7Bif(!w.open(u))l.href=u;%7D;if(/Firefox/.test(navigator.userAgent))setTimeout(a,0);else%20a();%7Dvoid(0);">Copies History</a>
</p>

</div>
</div>
</body></html>
