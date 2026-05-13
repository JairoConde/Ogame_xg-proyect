<br/>
<div id="content">
    <table width="700">
        <tr>
            <td class="c" colspan="2">{di_propose_peace_title}</td>
        </tr>
        <tr>
            <td colspan="2">
                <p>{di_peace_vs} {enemy_tag}</p>
                <p>{di_peace_explain}</p>
                <p>{di_peace_offer_label}: <strong>{offered}</strong></p>
                <p>{di_peace_required_peer}: {required_peer}</p>
                <form method="post" action="game.php?page=diplomacy&amp;action=propose_peace_post">
                    <input type="hidden" name="enemy_id" value="{enemy_id}" />
                    <p>
                        <button type="submit" class="button">{di_send_proposal}</button>
                        <a href="game.php?page=diplomacy">{di_back}</a>
                    </p>
                </form>
            </td>
        </tr>
    </table>
</div>
