create table teams
(
    team_id         serial
        primary key,
    name            varchar(100) not null,
    abbreviation    varchar(5)   not null,
    cap_space       numeric(10, 2) default 88.00,
    waiver_priority integer
);

alter table teams
    owner to postgres;

create table players
(
    player_id              serial
        primary key,
    team_id                integer
        references teams,
    first_name             varchar(50)                not null,
    last_name              varchar(50)                not null,
    position               varchar(2)                 not null,
    skating                integer      default 70,
    shooting               integer      default 70,
    passing                integer      default 70,
    defense                integer      default 70,
    physical               integer      default 70,
    goaltending            integer      default 50,
    condition              integer      default 100,
    is_injured             boolean      default false,
    line_number            integer      default 1,
    pp_unit                integer      default 0,
    pk_unit                integer      default 0,
    injury_days_remaining  integer      default 0,
    roster_status          varchar(10)  default 'PRO'::character varying,
    salary                 bigint       default 0,
    contract_years         integer      default 1,
    overall_rating         integer      default 70,
    consistency            integer      default 75,
    nhl_games_played       integer      default 0,
    on_trade_block         boolean      default false,
    trade_block_notes      text         default ''::text,
    nhl_id                 integer
        unique,
    fa_status              varchar(10),
    age                    integer,
    birth_date             date,
    is_retired             boolean      default false not null,
    rights_notes           text,
    contract_type          varchar(10),
    is_waiver_exempt       boolean      default true,
    waiver_exempt_override boolean,
    prev_team_id           integer,
    qo_tendered            boolean      default false,
    is_eligible_activate   boolean      default true  not null,
    rights_reason          varchar(100) default 'Rights Owned'::character varying,
    rights_added_at        timestamp    default CURRENT_TIMESTAMP
);

alter table players
    owner to postgres;

create index idx_players_active_status
    on players (team_id, is_retired, roster_status);

create unique index idx_unique_player_per_team
    on players (lower(TRIM(BOTH FROM first_name)), lower(TRIM(BOTH FROM last_name)), team_id)
    where (team_id IS NOT NULL);

create table games
(
    game_id       serial
        primary key,
    game_date     date not null,
    home_team_id  integer
        references teams,
    away_team_id  integer
        references teams,
    home_score    integer     default 0,
    away_score    integer     default 0,
    status        varchar(20) default 'SCHEDULED'::character varying,
    play_by_play  jsonb,
    is_overtime   boolean     default false,
    is_shootout   boolean     default false,
    home_p1_goals integer     default 0,
    home_p2_goals integer     default 0,
    home_p3_goals integer     default 0,
    home_ot_goals integer     default 0,
    away_p1_goals integer     default 0,
    away_p2_goals integer     default 0,
    away_p3_goals integer     default 0,
    away_ot_goals integer     default 0,
    home_p1_shots integer     default 0,
    home_p2_shots integer     default 0,
    home_p3_shots integer     default 0,
    home_ot_shots integer     default 0,
    away_p1_shots integer     default 0,
    away_p2_shots integer     default 0,
    away_p3_shots integer     default 0,
    away_ot_shots integer     default 0,
    home_pp_goals integer     default 0,
    home_pp_opps  integer     default 0,
    away_pp_goals integer     default 0,
    away_pp_opps  integer     default 0
);

alter table games
    owner to postgres;

create table player_game_stats
(
    stat_id        serial
        primary key,
    game_id        integer
        references games,
    player_id      integer
        references players,
    team_id        integer
        references teams,
    toi_seconds    integer default 0,
    goals          integer default 0,
    assists        integer default 0,
    shots          integer default 0,
    hits           integer default 0,
    pim            integer default 0,
    saves          integer default 0,
    goals_against  integer default 0,
    blocks         integer default 0,
    pp_toi_seconds integer default 0,
    pk_toi_seconds integer default 0
);

alter table player_game_stats
    owner to postgres;

create table gm_users
(
    user_id            serial
        primary key,
    team_id            integer
        references teams,
    username           varchar(50)  not null
        unique,
    email              varchar(150) not null,
    password_hash      varchar(255) not null,
    notify_trade_block boolean default true,
    notify_trades      boolean default true,
    is_admin           boolean default false
);

alter table gm_users
    owner to postgres;

create table prospects
(
    prospect_id   serial
        primary key,
    team_id       integer
        references teams,
    draft_year    integer     not null,
    round_num     integer     default 0,
    pick_num      integer     default 0,
    first_name    varchar(50) not null,
    last_name     varchar(50) not null,
    position      varchar(2)  not null,
    age           integer     default 18,
    potential     integer     default 75,
    status        varchar(20) default 'UNSIGNED'::character varying,
    prospect_type varchar(20) default 'PROSPECT'::character varying,
    list_category varchar(20) default 'PROSPECT'::character varying,
    is_euro       boolean     default false,
    overall_pick  integer     default 0,
    notes         text        default ''::text
);

alter table prospects
    owner to postgres;

create table team_lines
(
    team_id      integer                                                                                                not null
        primary key
        references teams
            on delete cascade,
    lineup_json  jsonb         default '{}'::jsonb                                                                      not null,
    fwd_toi_pct  integer[]     default ARRAY [32, 28, 22, 18]                                                           not null,
    def_toi_pct  integer[]     default ARRAY [40, 35, 25]                                                               not null,
    fwd_strategy varchar(15)[] default ARRAY ['OFFENSIVE'::text, 'BALANCED'::text, 'BALANCED'::text, 'DEFENSIVE'::text] not null,
    def_strategy varchar(15)[] default ARRAY ['OFFENSIVE'::text, 'BALANCED'::text, 'DEFENSIVE'::text]                   not null,
    updated_at   timestamp     default CURRENT_TIMESTAMP,
    pp1_strat    varchar(20)   default 'Balanced'::character varying,
    pp2_strat    varchar(20)   default 'Balanced'::character varying,
    pk1_strat    varchar(20)   default 'Balanced'::character varying,
    pk2_strat    varchar(20)   default 'Balanced'::character varying,
    f4_1_strat   varchar(20)   default 'Balanced'::character varying,
    f4_2_strat   varchar(20)   default 'Balanced'::character varying,
    ot1_strat    varchar(20)   default 'Balanced'::character varying,
    ot2_strat    varchar(20)   default 'Balanced'::character varying
);

alter table team_lines
    owner to postgres;

create table users
(
    user_id             serial
        primary key,
    username            varchar(50)  not null
        unique,
    password_hash       varchar(255) not null,
    email               varchar(150) default ''::character varying,
    team_id             integer
                                     references teams
                                         on delete set null,
    is_admin            boolean      default false,
    notify_trade_block  boolean      default true,
    created_at          timestamp    default CURRENT_TIMESTAMP,
    notify_games_simmed boolean      default true,
    notify_injuries     boolean      default true,
    is_commish          boolean      default false,
    is_trade_committee  boolean      default false,
    can_manage_draft    boolean      default false,
    is_fa_committee     boolean      default false
);

alter table users
    owner to postgres;

create table trade_block
(
    player_id   integer not null
        primary key
        references players
            on delete cascade,
    team_id     integer not null
        references teams
            on delete cascade,
    player_note varchar(200) default ''::character varying,
    added_at    timestamp    default CURRENT_TIMESTAMP,
    created_at  timestamp    default now()
);

alter table trade_block
    owner to postgres;

create table trade_block_notes
(
    team_id    integer not null
        primary key
        references teams
            on delete cascade,
    team_needs text      default ''::text,
    updated_at timestamp default CURRENT_TIMESTAMP
);

alter table trade_block_notes
    owner to postgres;

create table trades
(
    trade_id             serial
        primary key,
    proposing_team_id    integer                                             not null
        references teams
            on delete cascade,
    receiving_team_id    integer                                             not null
        references teams
            on delete cascade,
    offered_player_ids   integer[]   default ARRAY []::integer[]             not null,
    requested_player_ids integer[]   default ARRAY []::integer[]             not null,
    trade_notes          text        default ''::text,
    status               varchar(25) default 'PENDING_GM'::character varying not null,
    created_at           timestamp   default CURRENT_TIMESTAMP,
    updated_at           timestamp   default CURRENT_TIMESTAMP,
    offered_pick_ids     integer[]   default ARRAY []::integer[]             not null,
    requested_pick_ids   integer[]   default ARRAY []::integer[]             not null,
    parent_trade_id      integer
        references trades
);

alter table trades
    owner to postgres;

create table draft_picks
(
    pick_id          serial
        primary key,
    draft_year       integer not null,
    round_num        integer not null,
    original_team_id integer not null
        references teams
            on delete cascade,
    current_team_id  integer not null
        references teams
            on delete cascade,
    conditions       varchar(200) default ''::character varying,
    is_used          boolean      default false,
    unique (draft_year, round_num, original_team_id)
);

alter table draft_picks
    owner to postgres;

create table league_news
(
    news_id    serial
        primary key,
    title      varchar(200)                                          not null,
    body       text                                                  not null,
    author     varchar(80) default 'Commissioner'::character varying not null,
    created_at timestamp   default CURRENT_TIMESTAMP
);

alter table league_news
    owner to postgres;

create table contract_negotiations
(
    neg_id          serial
        primary key,
    player_id       integer                                                    not null
        references players,
    team_id         integer                                                    not null
        references teams,
    fa_status       varchar(10)                                                not null,
    proposed_years  integer                                                    not null,
    proposed_salary bigint                                                     not null,
    last_actor      varchar(15)                                                not null,
    last_reason     text,
    status          varchar(25) default 'PENDING_COMMITTEE'::character varying not null,
    created_at      timestamp   default now(),
    updated_at      timestamp   default now()
);

alter table contract_negotiations
    owner to postgres;

create table contract_offer_history
(
    history_id     serial
        primary key,
    neg_id         integer     not null
        references contract_negotiations
            on delete cascade,
    actor_role     varchar(15) not null,
    offered_years  integer     not null,
    offered_salary bigint      not null,
    reason         text,
    created_at     timestamp default now()
);

alter table contract_offer_history
    owner to postgres;

create table league_cba_rules
(
    season_year        integer           not null
        primary key,
    salary_cap         bigint            not null,
    min_salary         bigint            not null,
    max_salary         bigint            not null,
    max_contract_years integer default 4 not null
);

alter table league_cba_rules
    owner to postgres;

create table waiver_wire
(
    waiver_id          serial
        primary key,
    player_id          integer                                            not null
        references players,
    waiving_team_id    integer                                            not null
        references teams,
    placed_at          timestamp   default now()                          not null,
    expires_at         timestamp   default (now() + '24:00:00'::interval) not null,
    status             varchar(20) default 'ACTIVE'::character varying    not null,
    claimed_by_team_id integer
        references teams,
    resolved_at        timestamp
);

alter table waiver_wire
    owner to postgres;

create table waiver_claims
(
    claim_id         serial
        primary key,
    waiver_id        integer                 not null
        references waiver_wire
            on delete cascade,
    claiming_team_id integer                 not null
        references teams,
    claimed_at       timestamp default now() not null,
    unique (waiver_id, claiming_team_id)
);

alter table waiver_claims
    owner to postgres;

create table league_settings
(
    setting_key   varchar(50)  not null
        primary key,
    setting_value varchar(100) not null
);

alter table league_settings
    owner to postgres;

create table fa_bids
(
    bid_id         serial
        primary key,
    player_id      integer                                         not null
        references players
            on delete cascade,
    team_id        integer                                         not null
        references teams
            on delete cascade,
    offered_years  integer     default 1                           not null,
    offered_salary bigint      default 750000                      not null,
    dest_roster    varchar(10) default 'PRO'::character varying    not null,
    offer_reason   text        default ''::text,
    status         varchar(20) default 'ACTIVE'::character varying not null,
    updated_at     timestamp   default now()                       not null,
    match_deadline timestamp,
    unique (player_id, team_id)
);

alter table fa_bids
    owner to postgres;

create table nhl_cap_schedule
(
    season_year  integer     not null
        primary key,
    season_label varchar(20) not null,
    cap_max      bigint      not null,
    cap_min      bigint      not null
);

alter table nhl_cap_schedule
    owner to postgres;

create view team_cap_summary(team_id, team_name, abbreviation, active_cap_hit, buried_one_way_cap) as
SELECT t.team_id,
       t.name                                 AS team_name,
       t.abbreviation,
       COALESCE(sum(
                        CASE
                            WHEN COALESCE(p.is_retired, false) = true THEN 0::bigint
                            WHEN upper(TRIM(BOTH FROM COALESCE(p.roster_status, ''::character varying))) = 'PRO'::text
                                THEN COALESCE(p.salary, 0::bigint)
                            WHEN upper(TRIM(BOTH FROM COALESCE(p.roster_status, ''::character varying))) =
                                 'AHL'::text AND COALESCE(p.salary, 0::bigint) >= 2000000
                        THEN COALESCE(p.salary, 0::bigint)
                        ELSE 0::bigint
                        END), 0::numeric) AS active_cap_hit,
       COALESCE(sum(
                        CASE
                            WHEN COALESCE(p.is_retired, false) = false AND
                                 upper(TRIM(BOTH FROM COALESCE(p.roster_status, ''::character varying))) =
                                 'AHL'::text AND COALESCE(p.salary, 0::bigint) >= 2000000
                        THEN COALESCE(p.salary, 0::bigint)
                        ELSE 0::bigint
                        END), 0::numeric) AS buried_one_way_cap
FROM teams t
         LEFT JOIN players p ON p.team_id = t.team_id
GROUP BY t.team_id, t.name, t.abbreviation;

alter table team_cap_summary


