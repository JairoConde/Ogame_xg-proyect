<br />
<div id="content">
    <form name="top3" method="post">
        <table width="519">
            <tr>
                <td colspan="4" class="c">{top3_title} ({top3_updated}: {stat_date})</td>
            </tr>
            <tr>
                <th colspan="4" class="c">{top3_show} <select name="category" onChange="javascript:document.top3.submit()">{top3_category_select}</select></th>
            </tr>
        </table>
    </form>
    <table width="519">
        {top3_header}
        {top3_rows}
    </table>
</div>
