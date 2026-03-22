use dashmap::DashMap;
use lazy_static::lazy_static;
use std::io::{Read, Write};
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};
use serde::{Serialize, Deserialize};
use std::os::windows::io::FromRawHandle;
use std::fs::File;
use std::thread;

use windows_sys::Win32::Storage::FileSystem::{PIPE_ACCESS_DUPLEX, FILE_FLAG_FIRST_PIPE_INSTANCE};
use windows_sys::Win32::System::Pipes::{PIPE_TYPE_BYTE, PIPE_READMODE_BYTE, PIPE_WAIT, PIPE_UNLIMITED_INSTANCES, ConnectNamedPipe};
use windows_sys::Win32::Foundation::{INVALID_HANDLE_VALUE, HANDLE};

#[link(name = "kernel32")]
extern "system" {
    fn CreateNamedPipeW(
        lpname: *const u16,
        dwopenmode: u32,
        dwpipemode: u32,
        nmaxinstances: u32,
        noutbuffersize: u32,
        ninbuffersize: u32,
        ndefaulttimeout: u32,
        lpsecurityattributes: *const std::ffi::c_void,
    ) -> HANDLE;
}

#[derive(Serialize, Deserialize, Clone)]
struct CacheEntry {
    value: String,
    expires_at: u64,
}

lazy_static! {
    static ref CACHE: Arc<DashMap<String, CacheEntry>> = Arc::new(DashMap::new());
    static ref MAX_MEMORY: AtomicUsize = AtomicUsize::new(512 * 1024 * 1024);
    static ref CURRENT_USAGE: AtomicUsize = AtomicUsize::new(0);
}

#[inline(always)]
fn get_now() -> u64 {
    std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).unwrap().as_secs()
}

fn spawn_pipe_thread(is_first: bool) {
    thread::spawn(move || {
        let pipe_name: Vec<u16> = r"\\.\pipe\nitro_pipe".encode_utf16().chain(std::iter::once(0)).collect();
        let access_flags = if is_first {
            PIPE_ACCESS_DUPLEX | FILE_FLAG_FIRST_PIPE_INSTANCE
        } else {
            PIPE_ACCESS_DUPLEX
        };

        let mut payload = Vec::with_capacity(128 * 1024);

        loop {
            unsafe {
                let handle = CreateNamedPipeW(
                    pipe_name.as_ptr(),
                    access_flags,
                    PIPE_TYPE_BYTE | PIPE_READMODE_BYTE | PIPE_WAIT,
                    PIPE_UNLIMITED_INSTANCES,
                    256 * 1024,
                    256 * 1024,
                    0,
                    std::ptr::null()
                );

                if handle == INVALID_HANDLE_VALUE {
                    thread::sleep(std::time::Duration::from_millis(5));
                    continue;
                }

                if ConnectNamedPipe(handle, std::ptr::null_mut()) != 0 {
                    let mut file = File::from_raw_handle(handle as _);
                    let mut cmd_buf = [0u8; 1];
                    let mut len_buf = [0u8; 4];

                    while file.read_exact(&mut cmd_buf).is_ok() {
                        if file.read_exact(&mut len_buf).is_err() { break; }
                        let len = u32::from_le_bytes(len_buf) as usize;

                        payload.resize(len, 0); // Zero-alloc resize
                        if len > 0 && file.read_exact(&mut payload).is_err() { break; }

                        match cmd_buf[0] {
                            b'S' => { // SET
                                if let Ok((k, v, ttl)) = bincode::deserialize::<(String, String, u64)>(&payload) {
                                    let entry_size = k.len() + v.len() + 48; // Учитываем оверхед структуры
                                    if CURRENT_USAGE.load(Ordering::Relaxed) + entry_size <= MAX_MEMORY.load(Ordering::Relaxed) {
                                        CACHE.insert(k, CacheEntry { value: v, expires_at: get_now() + ttl });
                                        CURRENT_USAGE.fetch_add(entry_size, Ordering::Relaxed);
                                        let _ = file.write_all(&[1u8]);
                                    } else {
                                        let _ = file.write_all(&[0u8]);
                                    }
                                }
                            },
                            b'G' => { // GET
                                let key_str = String::from_utf8_lossy(&payload);
                                let mut response = "NULL".as_bytes();
                                let mut temp_val;

                                if let Some(e) = CACHE.get(key_str.as_ref()) {
                                    if e.expires_at > get_now() {
                                        temp_val = e.value.clone();
                                        response = temp_val.as_bytes();
                                    }
                                }

                                let _ = file.write_all(&(response.len() as u32).to_le_bytes());
                                let _ = file.write_all(response);
                            },
                            b'R' => { // REMOVE
                                let key_str = String::from_utf8_lossy(&payload);
                                if let Some((k, e)) = CACHE.remove(key_str.as_ref()) {
                                    let size = k.len() + e.value.len() + 48;
                                    CURRENT_USAGE.fetch_sub(size, Ordering::Relaxed);
                                }
                            },
                            b'M' => { // MEM USAGE
                                let usage = CURRENT_USAGE.load(Ordering::Relaxed);
                                let _ = file.write_all(&usage.to_le_bytes());
                            },
                            b'L' => { // LIMIT
                                if payload.len() == 8 {
                                    let mut b = [0u8; 8];
                                    b.copy_from_slice(&payload);
                                    MAX_MEMORY.store(usize::from_le_bytes(b), Ordering::Relaxed);
                                }
                            },
                            b'C' => { // CLEAR
                                CACHE.clear();
                                CURRENT_USAGE.store(0, Ordering::Relaxed);
                            },
                            _ => {}
                        }
                    }
                }
            }
        }
    });
}

fn main() {
    println!("🚀 NITRO SERVER V2: High-Performance Mode");
    println!("System: Windows Named Pipes | Engine: DashMap | Alloc: Zero-Copy-Loop");

    spawn_pipe_thread(true);
    for _ in 0..15 { spawn_pipe_thread(false); }
    loop { thread::park(); }
}