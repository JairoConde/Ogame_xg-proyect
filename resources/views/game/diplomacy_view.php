<br/>
<div id="content">
    <table width="700">
        <tr>
            <td class="c" colspan="7">{di_title}</td>
        </tr>
        <tr>
            <td colspan="7">{leader_strip}</td>
        </tr>
        <tr>
            <th class="c" colspan="7">{di_incoming_proposals}</th>
        </tr>
        {incoming_proposals}
        <tr>
            <td colspan="3">{other_tag}</td>
            <td colspan="2">{kind_label}</td>
            <td>{expires}</td>
            <td>{actions}</td>
        </tr>
        {/incoming_proposals}
        <tr>
            <th class="c" colspan="7">{di_outgoing_proposals}</th>
        </tr>
        {outgoing_proposals}
        <tr>
            <td colspan="3">{other_tag}</td>
            <td colspan="2">{kind_label}</td>
            <td colspan="2">{expires}</td>
        </tr>
        {/outgoing_proposals}
        <tr>
            <th class="c" colspan="7">{di_active_wars}</th>
        </tr>
        <tr>
            <td class="c">{di_declarer}</td>
            <td class="c">{di_vs}</td>
            <td class="c">{di_target}</td>
            <td class="c">{di_since}</td>
            <td class="c">{di_damage_dealt}</td>
            <td class="c">{di_damage_received}</td>
            <td class="c">{di_leader_actions}</td>
        </tr>
        {wars_rows}
        <tr>
            <th><a href="game.php?page=alliance&mode=ainfo&allyid={left_id}">{left_tag}</a></th>
            <th>→</th>
            <th><a href="game.php?page=alliance&mode=ainfo&allyid={right_id}">{right_tag}</a></th>
            <th>{since}</th>
            <th>{damage_left}</th>
            <th>{damage_right}</th>
            <th>{peace_link}</th>
        </tr>
        {/wars_rows}
        <tr>
            <th class="c" colspan="7">{di_active_pacts}</th>
        </tr>
        <tr>
            <td class="c" colspan="2">{di_alliance_a}</td>
            <td class="c" colspan="2">{di_alliance_b}</td>
            <td class="c">{di_since}</td>
            <td class="c" colspan="2">{di_expires}</td>
        </tr>
        {pacts_rows}
        <tr>
            <th colspan="2"><a href="game.php?page=alliance&mode=ainfo&allyid={left_id}">{left_tag}</a></th>
            <th colspan="2"><a href="game.php?page=alliance&mode=ainfo&allyid={right_id}">{right_tag}</a></th>
            <th>{since}</th>
            <th colspan="2">{expires}</th>
        </tr>
        {/pacts_rows}
    </table>
</div>
