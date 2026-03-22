use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::io::{Read, Write, BufReader, BufWriter};
use std::fs::OpenOptions;
use std::cell::RefCell;

const PIPE_NAME: &str = r"\\.\pipe\nitro_pipe";

struct PipeConnection {
    reader: BufReader<std::fs::File>,
    writer: BufWriter<std::fs::File>,
}

thread_local! {
    static PIPE: RefCell<Option<PipeConnection>> = RefCell::new(None);
}

fn get_pipe() -> Option<PipeConnection> {
    PIPE.with(|cell| {
        let mut opt = cell.borrow_mut();
        if opt.is_none() {
            if let Ok(file) = OpenOptions::new().read(true).write(true).open(PIPE_NAME) {
                if let Ok(read_clone) = file.try_clone() {
                    *opt = Some(PipeConnection {
                        reader: BufReader::with_capacity(256 * 1024, read_clone),
                        writer: BufWriter::with_capacity(256 * 1024, file),
                    });
                }
            }
        }
        if let Some(conn) = opt.as_ref() {
            if let (Ok(r), Ok(w)) = (conn.reader.get_ref().try_clone(), conn.writer.get_ref().try_clone()) {
                return Some(PipeConnection {
                    reader: BufReader::new(r),
                    writer: BufWriter::new(w),
                });
            }
        }
        None
    })
}

#[unsafe(no_mangle)]
pub extern "C" fn cache_set(key: *const c_char, value: *const c_char, ttl_sec: u64) -> bool {
    if key.is_null() || value.is_null() { return false; }

    let k = unsafe { CStr::from_ptr(key) }.to_string_lossy();
    let v = unsafe { CStr::from_ptr(value) }.to_string_lossy();

    // Сериализуем только для SET, так как тут 3 поля
    if let Ok(payload) = bincode::serialize(&(k.into_owned(), v.into_owned(), ttl_sec)) {
        if let Some(mut conn) = get_pipe() {
            let mut msg = Vec::with_capacity(payload.len() + 5);
            msg.push(b'S');
            msg.extend_from_slice(&(payload.len() as u32).to_le_bytes());
            msg.extend_from_slice(&payload);

            if conn.writer.write_all(&msg).is_ok() && conn.writer.flush().is_ok() {
                let mut ack = [0u8; 1];
                if conn.reader.read_exact(&mut ack).is_ok() {
                    return ack[0] == 1;
                }
            }
        }
    }
    false
}

#[unsafe(no_mangle)]
pub extern "C" fn cache_get(key: *const c_char) -> *mut c_char {
    if key.is_null() { return std::ptr::null_mut(); }
    let k = unsafe { CStr::from_ptr(key) }.to_bytes();

    if let Some(mut conn) = get_pipe() {
        let mut msg = Vec::with_capacity(k.len() + 5);
        msg.push(b'G');
        msg.extend_from_slice(&(k.len() as u32).to_le_bytes());
        msg.extend_from_slice(k);

        if conn.writer.write_all(&msg).is_ok() && conn.writer.flush().is_ok() {
            let mut resp_len_buf = [0u8; 4];
            if conn.reader.read_exact(&mut resp_len_buf).is_ok() {
                let resp_len = u32::from_le_bytes(resp_len_buf) as usize;
                if resp_len > 0 {
                    let mut resp_data = vec![0u8; resp_len];
                    if conn.reader.read_exact(&mut resp_data).is_ok() {
                        let s = String::from_utf8_lossy(&resp_data);
                        if s == "NULL" { return std::ptr::null_mut(); }
                        if let Ok(c_str) = CString::new(s.into_owned()) { return c_str.into_raw(); }
                    }
                }
            }
        }
    }
    std::ptr::null_mut()
}

#[unsafe(no_mangle)]
pub extern "C" fn cache_clear() {
    if let Some(mut conn) = get_pipe() {
        let _ = conn.writer.write_all(&[b'C', 0, 0, 0, 0]);
        let _ = conn.writer.flush();
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn set_max_memory(bytes: usize) {
    if let Some(mut conn) = get_pipe() {
        let payload = bytes.to_le_bytes();
        let mut msg = vec![b'L'];
        msg.extend_from_slice(&(payload.len() as u32).to_le_bytes());
        msg.extend_from_slice(&payload);
        let _ = conn.writer.write_all(&msg);
        let _ = conn.writer.flush();
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn get_memory_usage() -> usize {
    if let Some(mut conn) = get_pipe() {
        let _ = conn.writer.write_all(&[b'M', 0, 0, 0, 0]);
        let _ = conn.writer.flush();
        let mut res = [0u8; 8];
        if conn.reader.read_exact(&mut res).is_ok() { return usize::from_le_bytes(res); }
    }
    0
}

#[unsafe(no_mangle)]
pub extern "C" fn free_string(s: *mut c_char) {
    unsafe { if !s.is_null() { let _ = CString::from_raw(s); } }
}

#[unsafe(no_mangle)] pub extern "C" fn cache_remove(key: *const c_char) {
    if key.is_null() { return; }
    let k = unsafe { CStr::from_ptr(key) }.to_bytes();
    if let Some(mut conn) = get_pipe() {
        let mut msg = Vec::with_capacity(k.len() + 5);
        msg.push(b'R');
        msg.extend_from_slice(&(k.len() as u32).to_le_bytes());
        msg.extend_from_slice(k);
        let _ = conn.writer.write_all(&msg);
        let _ = conn.writer.flush();
    }
}

#[unsafe(no_mangle)] pub extern "C" fn init_persistent_storage() {}