<br/>
<div id="content">
    <form method="post" action="game.php?page=diplomacy&action=declare_post">
        <table width="520">
            <tr>
                <td class="c" colspan="2">{di_declare_war}</td>
            </tr>
            <tr>
                <td colspan="2">{cooldown_notice}</td>
            </tr>
            <tr>
                <th>{di_target_alliance}</th>
                <th>
                    <select name="target_alliance_id">
                        {options}
                    </select>
                </th>
            </tr>
            <tr>
                <th colspan="2"><input type="submit" value="{di_declare_war}"/></th>
            </tr>
            <tr>
                <th colspan="2" style="text-align:center;">
                    <a href="game.php?page=diplomacy">{di_back}</a>
                </th>
            </tr>
        </table>
    </form>
</div>
