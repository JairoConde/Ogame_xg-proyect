<div class="container-fluid">
    {alert}
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">{bs_title}</h1>
    </div>
    <p class="mb-2">{bs_subtitle}</p>
    <p class="small text-muted mb-4">{bs_retention_hint}</p>

    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">{bs_card_metrics}</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped" width="100%">
                            <thead>
                                <tr>
                                    <th>{bs_col_user_id}</th>
                                    <th>{bs_col_user_name}</th>
                                    <th class="text-center">{bs_col_alliance}</th>
                                    <th class="text-center">{bs_col_pending}</th>
                                    <th class="text-center">{bs_col_intel}</th>
                                    <th class="text-center">{bs_col_cargo_pressure}</th>
                                    {metric_header_cells}
                                </tr>
                            </thead>
                            <tbody>
                                {bot_metrics_tbody}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">{bs_card_alliance_health}</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm" width="100%">
                            <tbody>
                                {alliance_health_rows}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">{bs_card_bot_alliances}</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped" width="100%">
                            <thead>
                                <tr>
                                    <th>{bs_bot_allies_id}</th>
                                    <th>{bs_bot_allies_tag}</th>
                                    <th class="text-center">{bs_bot_allies_members}</th>
                                    <th class="text-center">{bs_bot_allies_pending}</th>
                                    <th>{bs_bot_allies_req}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {bot_alliances_tbody}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">{bs_card_logs}</h6>
                    <span class="small text-muted">{bs_log_total}: <strong>{log_disk_total}</strong></span>
                </div>
                <div class="card-body">
                    <p class="small">{bs_logs_help}</p>
                    <form method="post" action="" class="mb-3">
                        <input type="hidden" name="cleanup_logs" value="yes">
                        <button type="submit" class="btn btn-warning btn-sm" data-msg="{cleanup_data_msg}"
                            onclick="return confirm(this.getAttribute('data-msg'));">
                            {bs_cleanup_now}
                        </button>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm" width="100%">
                            <thead>
                                <tr>
                                    <th>{bs_log_file}</th>
                                    <th class="text-right">{bs_log_size}</th>
                                    <th>{bs_log_modified}</th>
                                    <th>{bs_log_age}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {log_files_tbody}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
