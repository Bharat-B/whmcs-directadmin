<h3 class="text-center">License Details</h3>
<table class="table">
    <tbody>
    <tr>
        <td>Status</td><td><span class="label label-{$active_label}">{$active}</span></td>
    </tr>
    <tr>
        <td>ID</td><td>{$license['lid']}</td>
    </tr>
    <tr>
        <td>IP</td><td>{$license['ip']}</td>
    </tr>
    <tr>
        <td>Verified</td><td>{$verified}</td>
    </tr>
    <tr>
        <td>Operating System</td><td>{$oslist[$license['os']]}</td>
    </tr>
    <tr>
        <td>Created On</td><td>{$license['start']}</td>
    </tr>
    <tr>
        <td>Expires On</td><td>{$license['expiry']}</td>
    </tr>
    </tbody>
</table>
