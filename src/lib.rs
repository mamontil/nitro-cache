use dashmap::DashMap;
use lazy_static::lazy_static;
use std::ffi::{CStr, CString};
use std::os::raw::c_char;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::time::{SystemTime, UNIX_EPOCH};

// Структура для хранения данных
struct CacheEntry {
    value: String,
    expires_at: u64,
    last_accessed: AtomicUsize, // Для LRU: храним время последнего доступа
}

// Статистика и лимиты (в байтах)
static MEMORY_USAGE: AtomicUsize = AtomicUsize::new(0);
static MAX_MEMORY: AtomicUsize = AtomicUsize::new(1024 * 1024 * 100); // 100MB по умолчанию

lazy_static! {
    static ref CACHE: DashMap<String, CacheEntry> = DashMap::new();
}

// Вспомогательная функция для получения текущего времени
fn get_now() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default().as_secs()
}

// Настройка лимита памяти из PHP
#[unsafe(no_mangle)]
pub extern "C" fn set_max_memory(bytes: usize) {
    MAX_MEMORY.store(bytes, Ordering::SeqCst);
}

// ВНУТРЕННЯЯ ФУНКЦИЯ: Очистка места (LRU)
// Если памяти не хватает, удаляем самый старый элемент
fn evict_if_needed(needed_size: usize) {
    let max = MAX_MEMORY.load(Ordering::SeqCst);

    // Пытаемся освободить место, пока его не станет достаточно
    while MEMORY_USAGE.load(Ordering::SeqCst) + needed_size > max {
        let mut oldest_key: Option<String> = None;
        let mut oldest_time = usize::MAX;

        // Выбираем кандидата на удаление (проверяем часть кэша для скорости)
        // Мы не блокируем весь кэш, а берем первые 20 элементов для анализа
        for r in CACHE.iter().take(20) {
            let last = r.value().last_accessed.load(Ordering::Relaxed);
            if last < oldest_time {
                oldest_time = last;
                oldest_key = Some(r.key().clone());
            }
        }

        if let Some(key) = oldest_key {
            if let Some((k, entry)) = CACHE.remove(&key) {
                let size = k.len() + entry.value.len();
                MEMORY_USAGE.fetch_sub(size, Ordering::SeqCst);
            }
        } else {
            break; // Если кэш пуст, выходим
        }
    }
}

// Запись данных
#[unsafe(no_mangle)]
pub extern "C" fn cache_set(key: *const c_char, value: *const c_char, ttl_sec: u64) {
    if key.is_null() || value.is_null() { return; }

    let c_str_key = unsafe { CStr::from_ptr(key) };
    let c_str_val = unsafe { CStr::from_ptr(value) };

    if let (Ok(k), Ok(v)) = (c_str_key.to_str(), c_str_val.to_str()) {
        let now = get_now();
        let new_size = k.len() + v.len();

        // 1. Освобождаем место, если нужно (LRU логика)
        evict_if_needed(new_size);

        // 2. Умный пересчет: если обновляем существующий ключ
        if let Some(old_entry) = CACHE.get(k) {
            let old_size = k.len() + old_entry.value.len();
            MEMORY_USAGE.fetch_sub(old_size, Ordering::SeqCst);
        }

        // 3. Сохранение
        MEMORY_USAGE.fetch_add(new_size, Ordering::SeqCst);
        CACHE.insert(k.to_string(), CacheEntry {
            value: v.to_string(),
            expires_at: now + ttl_sec,
            last_accessed: AtomicUsize::new(now as usize),
        });
    }
}

// Получение данных
#[unsafe(no_mangle)]
pub extern "C" fn cache_get(key: *const c_char) -> *mut c_char {
    if key.is_null() { return std::ptr::null_mut(); }
    let c_str_key = unsafe { CStr::from_ptr(key) };

    if let Ok(k) = c_str_key.to_str() {
        let now = get_now();

        if let Some(entry) = CACHE.get(k) {
            if entry.expires_at > now {
                // ОБНОВЛЯЕМ время доступа (для LRU)
                entry.last_accessed.store(now as usize, Ordering::Relaxed);

                return CString::new(entry.value.as_str()).unwrap().into_raw();
            } else {
                // Ленивая очистка протухших данных
                let size = k.len() + entry.value.len();
                drop(entry);
                if CACHE.remove(k).is_some() {
                    MEMORY_USAGE.fetch_sub(size, Ordering::SeqCst);
                }
            }
        }
    }
    std::ptr::null_mut()
}

// Остальные функции без изменений (они и так профессиональные)
#[unsafe(no_mangle)]
pub extern "C" fn cache_remove(key: *const c_char) {
    if key.is_null() { return; }
    let c_str_key = unsafe { CStr::from_ptr(key) };
    if let Ok(k) = c_str_key.to_str() {
        if let Some((_, entry)) = CACHE.remove(k) {
            let size = k.len() + entry.value.len();
            MEMORY_USAGE.fetch_sub(size, Ordering::SeqCst);
        }
    }
}

#[unsafe(no_mangle)]
pub extern "C" fn get_memory_usage() -> usize {
    MEMORY_USAGE.load(Ordering::SeqCst)
}

#[unsafe(no_mangle)]
pub extern "C" fn free_string(s: *mut c_char) {
    unsafe {
        if s.is_null() { return; }
        let _ = CString::from_raw(s);
    }
}