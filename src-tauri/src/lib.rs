use std::io::{BufRead, BufReader, Write};
use std::net::{SocketAddr, TcpStream};
use std::sync::Mutex;
use std::thread;
use std::time::{Duration, Instant};
use tauri::{Manager, RunEvent};
use tauri_plugin_shell::{process::CommandChild, ShellExt};
use tauri_plugin_sql::{Migration, MigrationKind};

const LOCAL_BRIDGE_SIDECAR: &str = "wsm-local-bridge";
const LOCAL_BRIDGE_API_PORT: u16 = 8765;

struct LocalBridgeProcess(Mutex<Option<CommandChild>>);

fn local_bridge_http_ready() -> bool {
    let address = SocketAddr::from(([127, 0, 0, 1], LOCAL_BRIDGE_API_PORT));
    let Ok(mut stream) = TcpStream::connect_timeout(&address, Duration::from_millis(250)) else {
        return false;
    };
    let _ = stream.set_read_timeout(Some(Duration::from_millis(500)));
    let request = format!(
        "GET /api/v1/health HTTP/1.1\r\nHost: 127.0.0.1:{LOCAL_BRIDGE_API_PORT}\r\nConnection: close\r\n\r\n"
    );
    if stream.write_all(request.as_bytes()).is_err() {
        return false;
    }

    let mut response = BufReader::new(stream);
    let mut status = String::new();
    if response.read_line(&mut status).is_err() || !status.starts_with("HTTP/1.") {
        return false;
    }

    let mut is_local_bridge = false;
    loop {
        let mut line = String::new();
        match response.read_line(&mut line) {
            Ok(0) | Err(_) => return false,
            Ok(_) => {}
        }
        if line == "\r\n" || line == "\n" {
            break;
        }
        if line.to_ascii_lowercase().starts_with("server:") && line.contains("WSM-Local-Bridge") {
            is_local_bridge = true;
        }
    }
    is_local_bridge
}

fn wait_for_local_bridge(timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    loop {
        if local_bridge_http_ready() {
            return true;
        }
        if Instant::now() >= deadline {
            return false;
        }
        thread::sleep(Duration::from_millis(120));
    }
}

fn local_database_migrations() -> Vec<Migration> {
    vec![
        Migration {
            version: 1,
            description: "create local API cache",
            sql: "CREATE TABLE IF NOT EXISTS api_cache (cache_key TEXT PRIMARY KEY NOT NULL, data_json TEXT NOT NULL, saved_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 2,
            description: "create local product cache",
            sql: "CREATE TABLE IF NOT EXISTS products (uuid TEXT PRIMARY KEY NOT NULL, remote_id INTEGER, sku TEXT NOT NULL DEFAULT '', barcode TEXT NOT NULL DEFAULT '', data_json TEXT NOT NULL, server_updated_at TEXT, updated_at INTEGER NOT NULL)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 3,
            description: "create local member cache",
            sql: "CREATE TABLE IF NOT EXISTS members (uuid TEXT PRIMARY KEY NOT NULL, remote_id INTEGER, phone TEXT NOT NULL DEFAULT '', data_json TEXT NOT NULL, server_updated_at TEXT, updated_at INTEGER NOT NULL)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 4,
            description: "create local sales records",
            sql: "CREATE TABLE IF NOT EXISTS sales (sale_uuid TEXT PRIMARY KEY NOT NULL, data_json TEXT NOT NULL, sync_status TEXT NOT NULL DEFAULT 'pending', created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 5,
            description: "create durable local sync queue",
            sql: "CREATE TABLE IF NOT EXISTS sync_queue (id TEXT PRIMARY KEY NOT NULL, action_type TEXT NOT NULL CHECK (action_type IN ('CREATE_SALE','CREATE_MEMBER')), payload_json TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','failed')), attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 6,
            description: "index local records for Delta lookup and queue order",
            sql: "CREATE INDEX IF NOT EXISTS idx_products_remote_id ON products(remote_id)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 7,
            description: "index local member lookup",
            sql: "CREATE INDEX IF NOT EXISTS idx_members_remote_phone ON members(remote_id, phone)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 8,
            description: "index local queue status and creation time",
            sql: "CREATE INDEX IF NOT EXISTS idx_sync_queue_status_created ON sync_queue(status, created_at)",
            kind: MigrationKind::Up,
        },
        Migration {
            version: 9,
            description: "index API cache expiry",
            sql: "CREATE INDEX IF NOT EXISTS idx_api_cache_expires ON api_cache(expires_at)",
            kind: MigrationKind::Up,
        },
    ]
}

pub fn run() {
    let sql_plugin = tauri_plugin_sql::Builder::default()
        .add_migrations("sqlite:wsm.db", local_database_migrations())
        .build();

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_shell::init())
        .plugin(tauri_plugin_http::init())
        .plugin(sql_plugin)
        .setup(|app| {
            match app
                .shell()
                .sidecar(LOCAL_BRIDGE_SIDECAR)
                .map(|command| command.arg("run").set_process_group(true))
                .and_then(|command| command.spawn())
            {
                Ok((mut events, child)) => {
                    app.manage(LocalBridgeProcess(Mutex::new(Some(child))));
                    tauri::async_runtime::spawn(async move {
                        while events.recv().await.is_some() {}
                    });
                    if !wait_for_local_bridge(Duration::from_secs(10)) {
                        eprintln!(
                            "WP Store Manager local bridge did not answer its loopback readiness probe within 10 seconds."
                        );
                    }
                }
                Err(error) => {
                    eprintln!("WP Store Manager local bridge sidecar did not start: {error}");
                }
            }
            Ok(())
        })
        .build(tauri::generate_context!())
        .expect("failed to build WP Store Manager Desktop");

    app.run(|app_handle, event| {
        if let RunEvent::Exit = event {
            if let Some(process) = app_handle.try_state::<LocalBridgeProcess>() {
                let child = process
                    .0
                    .lock()
                    .unwrap_or_else(|poisoned| poisoned.into_inner())
                    .take();
                if let Some(child) = child {
                    let _ = child.kill();
                }
            }
        }
    });
}
