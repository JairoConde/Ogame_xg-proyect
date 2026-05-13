<br/>
<div id="content">
    <table width="700">
        <tr>
            <td class="c" colspan="2">{di_propose_nap_title}</td>
        </tr>
        <tr>
            <td colspan="2">
                <form method="post" action="game.php?page=diplomacy&amp;action=propose_nap_post">
                    <p>{di_target_alliance}</p>
                    <p>
                        <select name="target_alliance_id" required>
                            <option value="">{di_pick_alliance}</option>
                            {options}
                        </select>
                    </p>
                    <p>{di_nap_duration_label}</p>
                    <p><input type="number" name="duration_days" min="1" max="30" value="7" /> {di_days}</p>
                    <p>
                        <button type="submit" class="button">{di_send_proposal}</button>
                        <a href="game.php?page=diplomacy">{di_back}</a>
                    </p>
                </form>
            </td>
        </tr>
    </table>
</div>
