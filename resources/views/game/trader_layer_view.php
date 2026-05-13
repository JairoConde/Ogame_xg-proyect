<div id="merchant">
    <div style="margin:0 auto; width: 665px;">
        {status_message}

        <form method="POST" action="game.php?page=traderLayer&mode=traderResources&sell={sell_resource}">
            <input type="hidden" name="sell" value="{sell_resource}">
            <table width="100%">
                <tr>
                    <td class="c" colspan="2">Mercader de recursos</td>
                </tr>
                <tr>
                    <th colspan="2" style="text-align:left">
                        Vendiendo: <strong>{sell_resource_name}</strong> | Disponible: {sell_available}
                    </th>
                </tr>
                <tr>
                    <th width="50%">
                        <img border="0" src="{dpath}resources/{resource_a}.gif" width="42" height="22"><br>
                        {resource_a_name}<br>
                        Actual: {resource_a_current}<br>
                        Espacio libre: {resource_a_free}<br>
                        Ratio: 1 {resource_a_name} = {ratio_a} {sell_resource_name}
                    </th>
                    <th width="50%">
                        <img border="0" src="{dpath}resources/{resource_b}.gif" width="42" height="22"><br>
                        {resource_b_name}<br>
                        Actual: {resource_b_current}<br>
                        Espacio libre: {resource_b_free}<br>
                        Ratio: 1 {resource_b_name} = {ratio_b} {sell_resource_name}
                    </th>
                </tr>
                <tr>
                    <th>
                        <input type="number" min="0" name="{resource_a}" value="0" style="width:140px">
                    </th>
                    <th>
                        <input type="number" min="0" name="{resource_b}" value="0" style="width:140px">
                    </th>
                </tr>
                <tr>
                    <th colspan="2" style="text-align:center">
                        Coste: {call_price} Materia Oscura (por llamada)<br><br>
                        <input type="submit" name="execute_trade" class="btn_blue" value="Intercambiar recursos">
                    </th>
                </tr>
            </table>
        </form>
    </div>
</div>
